<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Engine;

use OCA\AppleSync\Db\Item;
use OCA\AppleSync\Db\ItemMapper;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Http\ICloudHttp;
use OCA\AppleSync\ICloud\Photos\PhotosClient;
use OCA\AppleSync\ICloud\Photos\PhotosZoneException;
use OCA\AppleSync\Service\AccountService;
use OCA\AppleSync\Sync\Store\FileStore;
use OCA\AppleSync\Sync\SyncContext;
use OCA\AppleSync\Sync\SyncEngine;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\IConfig;

/**
 * iCloud Photos → "<target>/YYYY/MM/<original file name>" (default target
 * "/iCloud Photos"), dated in the user's time zone. Live Photos also get
 * their video as "<name>.MOV". Originals only, never edited versions.
 *
 * The first import pages through the whole library by position; it starts
 * by remembering the library's sync token, and afterwards each run asks
 * CloudKit only for what changed since that token.
 *
 * Items: remote id = CPLMaster record name (plus "#live" for the video),
 * collection = CPLAsset record name, etag = the resource's fingerprint,
 * local ref = file id, local path = path below the target folder.
 */
class PhotosSync implements SyncEngine {
	private const SERVICE = SyncState::SERVICE_PHOTOS;
	private const PAGE = 200;
	private const LIVE = '#live';

	/** File extensions for type identifiers, used when a photo has no file name. */
	private const EXTENSIONS = [
		'public.jpeg' => 'JPG', 'public.heic' => 'HEIC', 'public.heif' => 'HEIF', 'public.png' => 'PNG',
		'com.compuserve.gif' => 'GIF', 'public.tiff' => 'TIFF', 'com.adobe.raw-image' => 'DNG',
		'com.apple.quicktime-movie' => 'MOV', 'public.mpeg-4' => 'MP4', 'public.avi' => 'AVI',
	];

	public function __construct(
		private AccountService $accounts,
		private PhotosClient $photos,
		private ICloudHttp $http,
		private FileStore $files,
		private FileMirror $mirror,
		private ItemMapper $items,
		private IConfig $config,
		private ITimeFactory $time,
	) {
	}

	public function run(SyncContext $context): bool {
		$session = $this->accounts->ensureWebSession($context->userId);
		$cursor = $context->cursor();
		$target = '/' . trim($context->target(), '/');
		$this->files->ensureFolder($context->userId, $target);

		if (empty($cursor['indexed'])) {
			if ($this->photos->indexingState($session) !== 'FINISHED') {
				$context->skip('iCloud is still preparing the photo library; photos follow once it is done');
				return true;
			}
			$cursor['indexed'] = true;
		}
		$timezone = $this->timezone($context->userId);

		if (($cursor['mode'] ?? 'full') === 'full') {
			if (!$this->fullImport($context, $session, $cursor, $target, $timezone)) {
				return false;
			}
		}

		$restarted = false;
		for (;;) {
			if ($context->shouldStop()) {
				$context->saveCursor($cursor);
				return false;
			}
			try {
				$changes = $this->photos->changes($session, $cursor['token'] ?? null, self::PAGE);
			} catch (PhotosZoneException $e) {
				if ($restarted) {
					throw $e;
				}
				// Token no longer accepted: go through the whole library again
				// (files already here are not downloaded again).
				$restarted = true;
				$cursor = ['indexed' => true, 'mode' => 'full', 'rank' => 0];
				$context->saveCursor($cursor);
				if (!$this->fullImport($context, $session, $cursor, $target, $timezone)) {
					return false;
				}
				continue;
			}
			if (!$this->applyChanges($context, $session, $changes['records'], $target, $timezone)) {
				$context->saveCursor($cursor);
				return false; // same page again next run; what is done is skipped
			}
			$cursor['token'] = $changes['syncToken'];
			$context->saveCursor($cursor);
			if (!$changes['moreComing']) {
				return true;
			}
		}
	}

	/**
	 * Page through the library by position.
	 */
	private function fullImport(SyncContext $context, WebSession $session, array &$cursor, string $target, \DateTimeZone $timezone): bool {
		$cursor['mode'] = 'full';
		$rank = (int)($cursor['rank'] ?? 0);
		for (;;) {
			if ($context->shouldStop()) {
				$context->saveCursor($cursor);
				return false;
			}
			$page = $this->photos->page($session, $rank, self::PAGE);
			if ($rank === 0 || !isset($cursor['fullToken'])) {
				$cursor['fullToken'] = $page['syncToken'];
			}
			$pairs = $this->pairs($session, $page['records']);
			if ($pairs === []) {
				$cursor = ['indexed' => true, 'mode' => 'changes', 'token' => $cursor['fullToken'] ?? null];
				$context->saveCursor($cursor);
				return true;
			}
			$known = $this->known($context->userId, $pairs);
			foreach ($pairs as [$asset, $master]) {
				if ($master === null) {
					$context->skip('a photo whose file record is missing in iCloud');
				} elseif ($context->shouldStop() || !$this->syncAsset($context, $session, $asset, $master, $known, $target, $timezone)) {
					$cursor['rank'] = $rank;
					$context->saveCursor($cursor);
					return false;
				}
				$rank++;
			}
			$cursor['rank'] = $rank;
			$context->saveCursor($cursor);
		}
	}

	/**
	 * @param list<array> $records
	 * @return bool false when the budget ran out
	 */
	private function applyChanges(SyncContext $context, WebSession $session, array $records, string $target, \DateTimeZone $timezone): bool {
		$assets = [];
		$masters = [];
		foreach ($records as $record) {
			$name = (string)($record['recordName'] ?? '');
			if ($name === '') {
				continue;
			}
			$type = $record['recordType'] ?? null;
			if (!empty($record['deleted']) || $type === null) {
				$this->removeAsset($context, $name); // a deleted asset...
				$this->removeMaster($context, $name); // ...or master
			} elseif ($type === 'CPLAsset') {
				$assets[$name] = $record;
			} elseif ($type === 'CPLMaster') {
				$masters[$name] = $record;
			}
		}

		$pairs = [];
		foreach ($assets as $name => $asset) {
			if (self::flag($asset, 'isDeleted') || self::flag($asset, 'isExpunged') || self::flag($asset, 'isHidden')) {
				$this->removeAsset($context, $name);
				continue;
			}
			$pairs[] = $asset;
		}
		// A master that changed on its own (e.g. a replaced original) belongs
		// to an asset we already have: look that asset up.
		$covered = [];
		foreach ($pairs as $asset) {
			$covered[self::masterName($asset) ?? ''] = true;
		}
		$orphans = array_diff_key($masters, $covered);
		if ($orphans !== []) {
			$assetNames = [];
			foreach ($this->items->findByRemoteIds($context->userId, self::SERVICE, array_keys($orphans)) as $item) {
				$assetNames[] = $item->getCollection();
			}
			foreach ($this->photos->lookup($session, array_values(array_unique($assetNames))) as $asset) {
				if (($asset['recordType'] ?? null) === 'CPLAsset') {
					$pairs[] = $asset;
				}
			}
		}

		$resolved = $this->withMasters($session, $pairs, $masters);
		$known = $this->known($context->userId, $resolved);
		foreach ($resolved as [$asset, $master]) {
			if ($master === null) {
				continue;
			}
			if ($context->shouldStop() || !$this->syncAsset($context, $session, $asset, $master, $known, $target, $timezone)) {
				return false;
			}
		}
		return true;
	}

	/**
	 * Pair the assets of a query page with their masters, in page order.
	 *
	 * @param list<array> $records
	 * @return list<array{0: array, 1: ?array}>
	 */
	private function pairs(WebSession $session, array $records): array {
		$assets = [];
		$masters = [];
		foreach ($records as $record) {
			if (($record['recordType'] ?? null) === 'CPLAsset') {
				$assets[] = $record;
			} elseif (($record['recordType'] ?? null) === 'CPLMaster') {
				$masters[(string)$record['recordName']] = $record;
			}
		}
		return $this->withMasters($session, $assets, $masters);
	}

	/**
	 * @param list<array> $assets
	 * @param array<string, array> $masters known masters by record name
	 * @return list<array{0: array, 1: ?array}> every asset, with null when its master is not to be found
	 */
	private function withMasters(WebSession $session, array $assets, array $masters): array {
		$missing = [];
		foreach ($assets as $asset) {
			$name = self::masterName($asset);
			if ($name !== null && !isset($masters[$name])) {
				$missing[] = $name;
			}
		}
		if ($missing !== []) {
			$masters += $this->photos->lookup($session, array_values(array_unique($missing)));
		}
		$pairs = [];
		foreach ($assets as $asset) {
			$pairs[] = [$asset, $masters[self::masterName($asset) ?? ''] ?? null];
		}
		return $pairs;
	}

	/**
	 * @param list<array{0: array, 1: ?array}> $pairs
	 * @return array<string, Item> remote id => item, for the photos and their Live Photo videos
	 */
	private function known(string $userId, array $pairs): array {
		$ids = [];
		foreach ($pairs as [, $master]) {
			if ($master === null) {
				continue;
			}
			$ids[] = ItemMapper::key((string)$master['recordName']);
			$ids[] = ItemMapper::key($master['recordName'] . self::LIVE);
		}
		return $this->items->findByRemoteIds($userId, self::SERVICE, $ids);
	}

	/**
	 * Download (or move) one photo and its Live Photo video.
	 *
	 * @param array<string, Item> $known updated in place
	 * @return bool false when it did not fit into the budget
	 */
	private function syncAsset(SyncContext $context, WebSession $session, array $asset, array $master, array &$known, string $target, \DateTimeZone $timezone): bool {
		$masterName = (string)$master['recordName'];
		$assetName = (string)$asset['recordName'];
		$original = self::value($master, 'resOriginalRes');
		if (!is_array($original) || !is_string($original['downloadURL'] ?? null)) {
			$context->skip('a photo without a downloadable original');
			return true;
		}

		$timestamp = self::timestamp($asset, 'assetDate') ?? self::timestamp($asset, 'addedDate') ?? $this->time->getTime();
		$date = (new \DateTimeImmutable('@' . $timestamp))->setTimezone($timezone);
		$month = $date->format('Y/m');
		$name = $this->files->sanitizeName(self::fileName($master)) ?? $masterName . '.' . self::extension(self::value($master, 'resOriginalFileType'));

		$photoItem = $known[ItemMapper::key($masterName)] ?? null;
		$size = (int)($original['size'] ?? 0);
		$etag = (string)(self::value($master, 'resOriginalFingerprint') ?? $original['fileChecksum'] ?? $master['recordChangeTag'] ?? '');
		$relative = $this->place($context->userId, $target, $month, $name, $photoItem, $masterName, $size);
		$photoItem = $this->adopt($context->userId, $photoItem, $target . '/' . $relative, $size, $etag);

		[$result, $photoItem] = $this->mirror->put(
			$context, $photoItem, self::SERVICE, $assetName, $masterName, $etag,
			$target, $target . '/' . $relative, $relative, $size, $timestamp, $this->time->getTime(),
			fn () => $this->http->download($session, self::url((string)$original['downloadURL'], basename($relative))),
		);
		if ($result === FileMirror::DEFERRED) {
			return false;
		}
		if ($photoItem !== null) {
			$known[ItemMapper::key($masterName)] = $photoItem;
		}

		// Live Photo: the video goes next to the photo under the same base name.
		$video = self::value($master, 'resOriginalVidComplRes');
		if (is_array($video) && is_string($video['downloadURL'] ?? null)) {
			$base = pathinfo($relative, PATHINFO_FILENAME);
			$videoRelative = $month . '/' . $base . '.' . self::extension(self::value($master, 'resOriginalVidComplFileType'), 'MOV');
			$videoSize = (int)($video['size'] ?? 0);
			$videoEtag = (string)(self::value($master, 'resOriginalVidComplFingerprint') ?? $video['fileChecksum'] ?? $etag);
			$videoItem = $this->adopt($context->userId, $known[ItemMapper::key($masterName . self::LIVE)] ?? null, $target . '/' . $videoRelative, $videoSize, $videoEtag);
			[$result, $videoItem] = $this->mirror->put(
				$context, $videoItem, self::SERVICE, $assetName, $masterName . self::LIVE, $videoEtag,
				$target, $target . '/' . $videoRelative, $videoRelative, $videoSize, $timestamp, $this->time->getTime(),
				fn () => $this->http->download($session, self::url((string)$video['downloadURL'], basename($videoRelative))),
			);
			if ($result === FileMirror::DEFERRED) {
				return false;
			}
			if ($videoItem !== null) {
				$known[ItemMapper::key($masterName . self::LIVE)] = $videoItem;
			}
		}
		return true;
	}

	/**
	 * Where a photo goes below the target: "YYYY/MM/name". A photo keeps the
	 * name it got before; a different file already using the name gets
	 * "_<6 characters of the record name>" added.
	 */
	private function place(string $userId, string $target, string $month, string $name, ?Item $item, string $masterName, int $size): string {
		$previous = $item?->getLocalPath();
		if ($previous !== null && dirname($previous) === $month) {
			return $previous;
		}
		$relative = $month . '/' . $name;
		$existing = $this->files->infoAt($userId, $target . '/' . $relative);
		if ($existing === null || (string)$existing['id'] === $item?->getLocalRef()
			|| ($item === null && $existing['size'] === $size)) {
			return $relative;
		}
		$suffix = substr(preg_replace('/[^A-Za-z0-9]/', '', $masterName) ?: sha1($masterName), 0, 6);
		$dot = strrpos($name, '.');
		return $month . '/' . ($dot > 0 ? substr($name, 0, $dot) . '_' . $suffix . substr($name, $dot) : $name . '_' . $suffix);
	}

	/**
	 * A file of the same size already at the path (from an earlier install,
	 * or a reset) is taken over instead of downloaded again.
	 */
	private function adopt(string $userId, ?Item $item, string $path, int $size, string $etag): ?Item {
		if ($item !== null) {
			return $item;
		}
		$existing = $this->files->infoAt($userId, $path);
		if ($existing === null || $existing['size'] !== $size || $size === 0) {
			return null;
		}
		$item = new Item();
		$item->setLocalRef((string)$existing['id']);
		$item->setRemoteEtag($etag);
		return $item;
	}

	private function removeAsset(SyncContext $context, string $assetName): void {
		foreach ($this->items->findByCollection($context->userId, self::SERVICE, ItemMapper::key($assetName)) as $item) {
			$this->removeItem($context, $item);
		}
	}

	private function removeMaster(SyncContext $context, string $masterName): void {
		foreach ([$masterName, $masterName . self::LIVE] as $remoteId) {
			$item = $this->items->findByRemoteId($context->userId, self::SERVICE, ItemMapper::key($remoteId));
			if ($item !== null) {
				$this->removeItem($context, $item);
			}
		}
	}

	private function removeItem(SyncContext $context, Item $item): void {
		if ($context->mirrorDeletions() && $item->getLocalRef() !== null) {
			$this->files->delete($context->userId, (int)$item->getLocalRef());
		}
		$this->items->delete($item);
	}

	private function timezone(string $userId): \DateTimeZone {
		$name = $this->config->getUserValue($userId, 'core', 'timezone', '')
			?: $this->config->getSystemValueString('default_timezone', 'UTC');
		try {
			return new \DateTimeZone($name);
		} catch (\Throwable) {
			return new \DateTimeZone('UTC');
		}
	}

	private static function value(array $record, string $field): mixed {
		return $record['fields'][$field]['value'] ?? null;
	}

	private static function flag(array $record, string $field): bool {
		return (int)(self::value($record, $field) ?? 0) === 1;
	}

	private static function timestamp(array $record, string $field): ?int {
		$value = self::value($record, $field);
		return is_numeric($value) ? intdiv((int)$value, 1000) : null;
	}

	private static function masterName(array $asset): ?string {
		$name = self::value($asset, 'masterRef')['recordName'] ?? null;
		return is_string($name) && $name !== '' ? $name : null;
	}

	/**
	 * filenameEnc is base64 of the UTF-8 file name.
	 */
	private static function fileName(array $master): string {
		$encoded = self::value($master, 'filenameEnc');
		if (!is_string($encoded) || $encoded === '') {
			return '';
		}
		$decoded = base64_decode($encoded, true);
		return $decoded !== false && mb_check_encoding($decoded, 'UTF-8') ? trim($decoded) : '';
	}

	private static function extension(mixed $type, string $fallback = 'JPG'): string {
		return is_string($type) ? (self::EXTENSIONS[$type] ?? $fallback) : $fallback;
	}

	/**
	 * Some download URLs have a "${f}" placeholder for the file name.
	 */
	private static function url(string $url, string $fileName): string {
		return str_replace('${f}', rawurlencode($fileName), $url);
	}
}
