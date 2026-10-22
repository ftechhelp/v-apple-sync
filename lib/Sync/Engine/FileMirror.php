<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Engine;

use OCA\AppleSync\Db\Item;
use OCA\AppleSync\Db\ItemMapper;
use OCA\AppleSync\Sync\Store\FileStore;
use OCA\AppleSync\Sync\SyncContext;

/**
 * Keeps one Nextcloud file equal to one iCloud file: downloads when the etag
 * changed or the local copy is gone, and moves the local copy when only the
 * place changed (renamed in iCloud, or a new target folder).
 */
class FileMirror {
	public const UNCHANGED = 'unchanged';
	public const MOVED = 'moved';
	public const DOWNLOADED = 'downloaded';
	public const SKIPPED = 'skipped';
	/** It does not fit into this run's byte budget; try again next run. */
	public const DEFERRED = 'deferred';

	public function __construct(
		private FileStore $files,
		private ItemMapper $items,
	) {
	}

	/**
	 * @param string $root the service's target folder; old copies are only removed inside it
	 * @param string $path where the file belongs, relative to the user's home
	 * @param string $localPath what to remember as the item's local path
	 * @param callable(): (resource|string|null) $open the content, or null when iCloud has none to give
	 * @return array{0: string, 1: ?Item} one of the constants, and the item when there is one
	 */
	public function put(SyncContext $context, ?Item $item, string $service, string $collection, string $remoteId,
		string $etag, string $root, string $path, string $localPath, int $size, ?int $mtime, int $seenAt, callable $open): array {
		$userId = $context->userId;
		$localId = $item?->getLocalRef() !== null ? (int)$item->getLocalRef() : null;

		if ($item !== null && $localId !== null && $item->getRemoteEtag() === $etag) {
			$current = $this->files->pathOf($userId, $localId);
			if ($current === $path) {
				if ($item->getId() === null) {
					// An existing file taken over: remember it.
					$item = $this->save($item, $userId, $service, $collection, $remoteId, $etag, $localId, $localPath, $seenAt);
				}
				return [self::UNCHANGED, $item];
			}
			if ($current !== null && $this->files->move($userId, $localId, $path)) {
				return [self::MOVED, $this->save($item, $userId, $service, $collection, $remoteId, $etag, $localId, $localPath, $seenAt)];
			}
		}

		if (!$context->fits($size)) {
			return [self::DEFERRED, $item];
		}
		$content = $size === 0 ? '' : $open();
		if ($content === null) {
			$context->skip('iCloud offered no download for ' . basename($path));
			return [self::SKIPPED, $item];
		}
		try {
			$written = $this->files->write($userId, $path, $content, $mtime);
		} finally {
			if (is_resource($content)) {
				fclose($content);
			}
		}
		if ($localId !== null && $localId !== $written['id']
			&& str_starts_with((string)$this->files->pathOf($userId, $localId), rtrim($root, '/') . '/')) {
			// The old copy is still in the old place (moved and changed in iCloud).
			$this->files->delete($userId, $localId);
		}
		$context->countItem();
		$context->countBytes($written['size']);
		return [self::DOWNLOADED, $this->save($item ?? new Item(), $userId, $service, $collection, $remoteId, $etag, $written['id'], $localPath, $seenAt)];
	}

	private function save(Item $item, string $userId, string $service, string $collection, string $remoteId, string $etag, int $localId, string $localPath, int $seenAt): Item {
		$item->setUserId($userId);
		$item->setService($service);
		$item->setCollection($collection);
		$item->setRemoteId(ItemMapper::key($remoteId));
		$item->setRemoteEtag($etag);
		$item->setLocalRef((string)$localId);
		$item->setLocalPath($localPath);
		$item->setUpdatedAt($seenAt);
		return $this->items->save($item);
	}
}
