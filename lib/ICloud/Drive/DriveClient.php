<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud\Drive;

use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCA\AppleSync\ICloud\Http\ICloudHttp;
use OCP\Http\Client\IResponse;

/**
 * Read-only iCloud Drive client (drivews for listings, docws for downloads),
 * as used by www.icloud.com. Ported from pyicloud's DriveService.
 */
class DriveClient {
	public function __construct(
		private ICloudHttp $http,
	) {
	}

	/**
	 * Details of several folders, each with its "items".
	 *
	 * @param list<array{0: string, 1?: mixed}> $folders [drivewsid, shareID or null]
	 * @return array<string, array> drivewsid => folder; folders that are gone are left out
	 */
	public function folders(WebSession $session, array $folders): array {
		$payload = [];
		foreach ($folders as $folder) {
			$entry = ['drivewsid' => $folder[0], 'partialData' => false];
			if (!empty($folder[1])) {
				$entry['shareID'] = $folder[1];
			}
			$payload[] = $entry;
		}
		$response = $this->http->service($session, 'POST', $session->serviceUrl('drivews') . '/retrieveItemDetailsInFolders', [], [
			'json' => $payload,
		]);
		$data = $this->expectOk($response, 'list iCloud Drive folders');

		$found = [];
		foreach ($data as $folder) {
			if (is_array($folder) && isset($folder['drivewsid']) && is_array($folder['items'] ?? null)) {
				$found[(string)$folder['drivewsid']] = $folder;
			}
		}
		return $found;
	}

	/**
	 * Signed URL to download a file's content, or null if Apple offered none.
	 */
	public function downloadUrl(WebSession $session, array $item): ?string {
		$zone = (string)($item['zone'] ?? 'com.apple.CloudDocs');
		$path = '/ws/' . rawurlencode($zone) . '/download/by_id';
		// Shared-folder documents live in the owner's zone.
		$owner = $item['owner']['ownerRecordName'] ?? null;
		if ((!empty($item['shareID']) || !empty($item['share'])) && is_string($owner) && $owner !== '') {
			$path .= '/' . rawurlencode($owner);
		}
		$response = $this->http->service($session, 'GET', $session->serviceUrl('docws') . $path, [
			'document_id' => (string)($item['docwsid'] ?? ''),
		]);
		$data = $this->expectOk($response, 'prepare an iCloud Drive download');
		$url = $data['data_token']['url'] ?? $data['package_token']['url'] ?? null;
		return is_string($url) && $url !== '' ? $url : null;
	}

	private function expectOk(IResponse $response, string $what): array {
		$status = $response->getStatusCode();
		$data = ICloudHttp::json($response);
		if ($status !== 200) {
			$detail = ICloudHttp::errorMessage($data);
			throw new ICloudException(sprintf('Could not %s (HTTP %d%s)', $what, $status, $detail !== null ? ': ' . $detail : ''), $status);
		}
		return $data;
	}
}
