<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Engine;

use OCA\AppleSync\Db\Item;
use OCA\AppleSync\Db\ItemMapper;
use OCA\AppleSync\Db\SyncState;
use OCA\AppleSync\ICloud\Auth\WebSession;
use OCA\AppleSync\ICloud\Constants;
use OCA\AppleSync\ICloud\Drive\DriveClient;
use OCA\AppleSync\ICloud\Http\ICloudHttp;
use OCA\AppleSync\Service\AccountService;
use OCA\AppleSync\Sync\Store\FileStore;
use OCA\AppleSync\Sync\SyncContext;
use OCA\AppleSync\Sync\SyncEngine;
use OCP\AppFramework\Utility\ITimeFactory;

/**
 * iCloud Drive → the target folder (default "/iCloud Drive").
 *
 * Every run continues a breadth-first walk of the whole drive; the queue of
 * folders still to list lives in the cursor. Files are downloaded only when
 * their etag changed, and moved when only their place changed. After a
 * complete walk, items not seen during it were deleted in iCloud: their local
 * copies are removed when mirroring deletions, and forgotten otherwise.
 *
 * Items: collection "file" or "folder", remote id = drivewsid, local ref =
 * Nextcloud file id, local path = path below the target folder,
 * updated_at = start of the last walk that saw it.
 */
class DriveSync implements SyncEngine {
	private const SERVICE = SyncState::SERVICE_DRIVE;
	private const FOLDERS_PER_REQUEST = 10;
	private const FILE = 'file';
	private const FOLDER = Item::COLLECTION_FOLDER;

	public function __construct(
		private AccountService $accounts,
		private DriveClient $drive,
		private ICloudHttp $http,
		private FileStore $files,
		private FileMirror $mirror,
		private ItemMapper $items,
		private ITimeFactory $time,
	) {
	}

	public function run(SyncContext $context): bool {
		$session = $this->accounts->ensureWebSession($context->userId);
		$cursor = $context->cursor();
		$target = '/' . trim($context->target(), '/');
		$this->files->ensureFolder($context->userId, $target);

		$walk = $cursor['walk'] ?? null;
		if (!is_array($walk)) {
			$walk = ['startedAt' => $this->time->getTime(), 'queue' => [[Constants::DRIVE_ROOT, '', null]]];
		}

		while ($walk['queue'] !== []) {
			if ($context->shouldStop()) {
				$context->saveCursor(['walk' => $walk] + $cursor);
				return false;
			}
			$batch = array_splice($walk['queue'], 0, self::FOLDERS_PER_REQUEST);
			$details = $this->drive->folders($session, array_map(static fn (array $entry): array => [$entry[0], $entry[2] ?? null], $batch));
			foreach ($batch as $i => [$id, $relative]) {
				$folder = $details[$id] ?? null;
				if ($folder === null) {
					continue; // deleted while we walked
				}
				$subfolders = $this->syncFolder($context, $session, $folder, (string)$relative, $target, (int)$walk['startedAt']);
				if ($subfolders === null) {
					// Out of budget inside this folder: list it again next run.
					array_unshift($walk['queue'], ...array_slice($batch, $i));
					$context->saveCursor(['walk' => $walk] + $cursor);
					return false;
				}
				array_push($walk['queue'], ...$subfolders);
				$context->saveCursor(['walk' => $walk] + $cursor);
			}
		}

		if (!$this->removeUnseen($context, (int)$walk['startedAt'])) {
			$context->saveCursor(['walk' => $walk] + $cursor);
			return false;
		}
		$context->saveCursor(['lastWalk' => (int)$walk['startedAt']]);
		return true;
	}

	/**
	 * Sync the files of one listed folder.
	 *
	 * @return list<array{0: string, 1: string, 2: mixed}>|null its subfolders to walk, or null when the budget ran out
	 */
	private function syncFolder(SyncContext $context, WebSession $session, array $folder, string $relative, string $target, int $walkStart): ?array {
		$userId = $context->userId;
		if ($relative !== '') {
			$this->syncFolderItem($context, (string)$folder['drivewsid'], $relative, $target, $walkStart);
		}

		$children = array_values(array_filter($folder['items'], static fn ($child): bool => is_array($child) && isset($child['drivewsid'])));
		$known = $this->items->findByRemoteIds($userId, self::SERVICE, array_map(
			static fn (array $child): string => ItemMapper::key((string)$child['drivewsid']), $children));

		$subfolders = [];
		$seen = [];
		$used = [];
		foreach ($children as $child) {
			$name = $this->files->sanitizeName(self::name($child));
			if ($name === null) {
				$context->skip('a name Nextcloud does not accept: ' . self::name($child));
				continue;
			}
			$name = self::unique($name, $used);
			$childRelative = $relative === '' ? $name : $relative . '/' . $name;
			$type = strtoupper((string)($child['type'] ?? ''));
			if ($type === 'FOLDER' || $type === 'APP_LIBRARY') {
				$subfolders[] = [(string)$child['drivewsid'], $childRelative, $child['shareID'] ?? null];
				continue;
			}
			if ($type !== 'FILE') {
				continue;
			}
			if ($context->shouldStop()) {
				$this->items->markSeen($seen, $walkStart);
				return null;
			}

			$item = $known[ItemMapper::key((string)$child['drivewsid'])] ?? null;
			$size = (int)($child['size'] ?? 0);
			$mtime = isset($child['dateModified']) ? (strtotime((string)$child['dateModified']) ?: null) : null;
			[$result, $item] = $this->mirror->put(
				$context, $item, self::SERVICE, self::FILE, (string)$child['drivewsid'], (string)($child['etag'] ?? ''),
				$target, $target . '/' . $childRelative, $childRelative, $size, $mtime, $walkStart,
				fn () => $this->open($session, $child),
			);
			if ($result === FileMirror::DEFERRED) {
				$this->items->markSeen($seen, $walkStart);
				return null;
			}
			if ($result === FileMirror::UNCHANGED && $item instanceof Item) {
				$seen[] = $item->getId();
			}
		}
		$this->items->markSeen($seen, $walkStart);
		return $subfolders;
	}

	/**
	 * Create the local folder, moving the old one if iCloud renamed or moved it.
	 */
	private function syncFolderItem(SyncContext $context, string $drivewsid, string $relative, string $target, int $walkStart): void {
		$userId = $context->userId;
		$path = $target . '/' . $relative;
		$item = $this->items->findByRemoteId($userId, self::SERVICE, ItemMapper::key($drivewsid));
		if ($item?->getLocalRef() !== null) {
			$current = $this->files->pathOf($userId, (int)$item->getLocalRef());
			if ($current !== null && $current !== $path) {
				$this->files->move($userId, (int)$item->getLocalRef(), $path);
			}
		}
		$folderId = $this->files->ensureFolder($userId, $path);
		$item ??= new Item();
		$item->setUserId($userId);
		$item->setService(self::SERVICE);
		$item->setCollection(self::FOLDER);
		$item->setRemoteId(ItemMapper::key($drivewsid));
		$item->setLocalRef((string)$folderId);
		$item->setLocalPath($relative);
		$item->setUpdatedAt($walkStart);
		$this->items->save($item);
	}

	/**
	 * Forget (or, when mirroring deletions, delete) what the finished walk did not see:
	 * files first, then folders deepest first, and folders only when empty.
	 */
	private function removeUnseen(SyncContext $context, int $walkStart): bool {
		$userId = $context->userId;
		foreach ([self::FILE, self::FOLDER] as $collection) {
			while (($unseen = $this->items->findNotSeenSince($userId, self::SERVICE, $walkStart, $collection)) !== []) {
				if ($collection === self::FOLDER) {
					usort($unseen, static fn (Item $a, Item $b): int => substr_count((string)$b->getLocalPath(), '/') <=> substr_count((string)$a->getLocalPath(), '/'));
				}
				foreach ($unseen as $item) {
					if ($context->mirrorDeletions() && $item->getLocalRef() !== null) {
						$this->files->delete($userId, (int)$item->getLocalRef());
					}
					$this->items->delete($item);
				}
				if ($context->shouldStop()) {
					return false;
				}
			}
		}
		return true;
	}

	/**
	 * @return resource|null
	 */
	private function open(WebSession $session, array $child) {
		$url = $this->drive->downloadUrl($session, $child);
		return $url === null ? null : $this->http->download($session, $url);
	}

	private static function name(array $child): string {
		$name = (string)($child['name'] ?? '');
		if ($name === '') {
			$name = (string)$child['drivewsid'];
		}
		$extension = (string)($child['extension'] ?? '');
		return $extension !== '' ? $name . '.' . $extension : $name;
	}

	/**
	 * Two iCloud names can become the same once sanitized; number the later ones.
	 *
	 * @param array<string, true> $used lower-cased names already taken in this folder
	 */
	private static function unique(string $name, array &$used): string {
		$candidate = $name;
		for ($n = 2; isset($used[mb_strtolower($candidate)]); $n++) {
			$dot = strrpos($name, '.');
			$candidate = $dot > 0
				? substr($name, 0, $dot) . " ($n)" . substr($name, $dot)
				: "$name ($n)";
		}
		$used[mb_strtolower($candidate)] = true;
		return $candidate;
	}
}
