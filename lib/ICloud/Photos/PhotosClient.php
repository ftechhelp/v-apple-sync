<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Photos;

use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Constants;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCA\AppleSync\ICloud\Http\ICloudHttp;

/**
 * Read-only client for the iCloud Photos library (CloudKit container
 * com.apple.photos.cloud, private database, zone PrimarySync), as used by
 * www.icloud.com. Ported from pyicloud's photos_cloudkit service.
 *
 * Every photo or video is a CPLAsset (date, flags, masterRef) pointing at a
 * CPLMaster (file name and the original file's resources).
 */
class PhotosClient {
	public const ZONE = ['zoneName' => Constants::PHOTOS_ZONE, 'zoneType' => 'REGULAR_CUSTOM_ZONE'];

	private const DESIRED_KEYS = [
		'recordName', 'recordType', 'recordChangeTag', 'itemType', 'filenameEnc',
		'resOriginalRes', 'resOriginalFileType', 'resOriginalFingerprint',
		'resOriginalVidComplRes', 'resOriginalVidComplFileType', 'resOriginalVidComplFingerprint',
		'masterRef', 'assetDate', 'addedDate', 'isDeleted', 'isExpunged', 'isHidden',
	];

	/** CloudKit zone errors after which retrying later is the fix. */
	private const TRANSIENT_ERRORS = ['THROTTLED', 'TRY_AGAIN_LATER', 'SERVICE_UNAVAILABLE', 'INTERNAL_ERROR'];

	public function __construct(
		private ICloudHttp $http,
	) {
	}

	/**
	 * "FINISHED" once iCloud has indexed the library for the web.
	 */
	public function indexingState(WebSession $session): ?string {
		$data = $this->post($session, '/records/query', [
			'query' => ['recordType' => 'CheckIndexingState'],
			'zoneID' => self::ZONE,
		]);
		$state = $data['records'][0]['fields']['state']['value'] ?? null;
		return is_string($state) ? $state : null;
	}

	/**
	 * One page of all photos and videos (without hidden and deleted ones),
	 * oldest first, starting at position $startRank. The page holds the assets
	 * and their masters.
	 *
	 * @return array{records: list<array>, syncToken: ?string}
	 */
	public function page(WebSession $session, int $startRank, int $limit): array {
		$data = $this->post($session, '/records/query', [
			'query' => [
				'recordType' => Constants::PHOTOS_ALL_QUERY,
				'filterBy' => [
					['fieldName' => 'direction', 'comparator' => 'EQUALS', 'fieldValue' => ['type' => 'STRING', 'value' => 'ASCENDING']],
					['fieldName' => 'startRank', 'comparator' => 'EQUALS', 'fieldValue' => ['type' => 'INT64', 'value' => $startRank]],
				],
			],
			'zoneID' => self::ZONE,
			'desiredKeys' => self::DESIRED_KEYS,
			'resultsLimit' => $limit,
		]);
		return [
			'records' => array_values(array_filter($data['records'] ?? [], 'is_array')),
			'syncToken' => is_string($data['syncToken'] ?? null) ? $data['syncToken'] : null,
		];
	}

	/**
	 * Changed and deleted records since a sync token.
	 *
	 * @return array{records: list<array>, syncToken: ?string, moreComing: bool}
	 * @throws PhotosZoneException when CloudKit refuses the token or the zone
	 */
	public function changes(WebSession $session, ?string $syncToken, int $limit): array {
		$zone = [
			'zoneID' => self::ZONE,
			'desiredRecordTypes' => ['CPLAsset', 'CPLMaster'],
			'desiredKeys' => self::DESIRED_KEYS,
			'reverse' => false,
		];
		if ($syncToken !== null) {
			$zone['syncToken'] = $syncToken;
		}
		$data = $this->post($session, '/changes/zone', ['zones' => [$zone], 'resultsLimit' => $limit]);
		$result = $data['zones'][0] ?? [];
		return [
			'records' => array_values(array_filter($result['records'] ?? [], 'is_array')),
			'syncToken' => is_string($result['syncToken'] ?? null) ? $result['syncToken'] : $syncToken,
			'moreComing' => (bool)($result['moreComing'] ?? false),
		];
	}

	/**
	 * Records by name; names CloudKit does not know are left out.
	 *
	 * @param list<string> $recordNames
	 * @return array<string, array> recordName => record
	 */
	public function lookup(WebSession $session, array $recordNames): array {
		if ($recordNames === []) {
			return [];
		}
		$data = $this->post($session, '/records/lookup', [
			'records' => array_map(static fn (string $name): array => ['recordName' => $name], array_values($recordNames)),
			'zoneID' => self::ZONE,
			'desiredKeys' => self::DESIRED_KEYS,
		]);
		$found = [];
		foreach ($data['records'] ?? [] as $record) {
			if (is_array($record) && isset($record['recordName'], $record['recordType'])) {
				$found[(string)$record['recordName']] = $record;
			}
		}
		return $found;
	}

	private function post(WebSession $session, string $path, array $payload): array {
		$url = $session->serviceUrl('ckdatabasews') . '/database/1/' . Constants::PHOTOS_CONTAINER . '/production/private' . $path;
		$response = $this->http->service($session, 'POST', $url, ['remapEnums' => 'true', 'getCurrentSyncToken' => 'true'], [
			'json' => $payload,
		]);
		$status = $response->getStatusCode();
		$data = ICloudHttp::json($response);
		if ($status === 401 || $status === 403 || $status === 421) {
			throw new ICloudException(sprintf('iCloud Photos refused access (HTTP %d). Check that Advanced Data Protection is off and Access iCloud Data on the Web is on.', $status), $status);
		}
		if ($status !== 200) {
			$reason = $data['reason'] ?? $data['serverErrorCode'] ?? null;
			throw new ICloudException(sprintf('iCloud Photos request failed (HTTP %d%s)', $status, is_string($reason) ? ': ' . $reason : ''), $status);
		}
		foreach ($data['zones'] ?? [] as $zone) {
			if (is_array($zone) && isset($zone['serverErrorCode'])) {
				$code = (string)$zone['serverErrorCode'];
				$message = sprintf('iCloud Photos: %s%s', $code, isset($zone['reason']) ? ' (' . $zone['reason'] . ')' : '');
				if (in_array($code, self::TRANSIENT_ERRORS, true)) {
					throw new ICloudException($message, 503);
				}
				throw new PhotosZoneException($message, $code);
			}
		}
		return $data;
	}
}
