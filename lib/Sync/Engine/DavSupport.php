<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Engine;

use OCA\AppleSync\Dav\DavAuthException;
use OCA\AppleSync\Dav\DavClient;
use OCA\AppleSync\Dav\DavCollection;
use OCA\AppleSync\Dav\DavCredentials;
use OCA\AppleSync\Db\Item;
use OCA\AppleSync\Db\ItemMapper;
use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCA\AppleSync\Service\AccountService;
use OCA\AppleSync\Sync\SyncContext;

/**
 * Shared steps of the CardDAV and CalDAV engines.
 */
trait DavSupport {
	private function credentials(AccountService $accounts, SyncContext $context): DavCredentials {
		return $accounts->davCredentials($accounts->getAccount($context->userId))
			?? throw new DavAuthException('No app-specific password is stored', 401);
	}

	/**
	 * Collections in the home, rediscovering the home when the cached one stopped working.
	 *
	 * @return list<DavCollection>
	 */
	private function collections(DavClient $dav, DavCredentials $credentials, string $entry, string $kind, array &$cursor): array {
		$home = $cursor['home'] ?? null;
		if (is_string($home)) {
			try {
				return $dav->listCollections($credentials, $home, $kind);
			} catch (DavAuthException $e) {
				throw $e;
			} catch (ICloudException) {
				// Apple moved the account; look the home up again.
			}
		}
		$cursor['home'] = $dav->findHome($credentials, $entry, $kind);
		return $dav->listCollections($credentials, $cursor['home'], $kind);
	}

	/**
	 * Members that are new, changed in iCloud, or missing in Nextcloud.
	 *
	 * @param array<string, string> $remote path => etag
	 * @param array<string, Item> $known remote id => item
	 * @param array<string, true> $localUris
	 * @param callable(Item, string): string $expectedEtag
	 * @return list<string> paths
	 */
	private function changedMembers(array $remote, array $known, array $localUris, callable $expectedEtag): array {
		$changed = [];
		foreach ($remote as $path => $etag) {
			$item = $known[ItemMapper::key($path)] ?? null;
			if ($item === null || $item->getRemoteEtag() !== $expectedEtag($item, $etag)) {
				$changed[] = $path;
			} elseif ($item->getLocalRef() !== null && !isset($localUris[$item->getLocalRef()])) {
				$changed[] = $path; // deleted in Nextcloud; iCloud wins
			}
		}
		return $changed;
	}

	/**
	 * @param array<string, string> $remote path => etag
	 * @return array<string, true> item keys of the remote paths
	 */
	private static function remoteKeys(array $remote): array {
		$keys = [];
		foreach (array_keys($remote) as $path) {
			$keys[ItemMapper::key((string)$path)] = true;
		}
		return $keys;
	}

	/**
	 * @return array<string, Item> remote id => item
	 */
	private function knownItems(ItemMapper $items, string $userId, string $service, string $collection): array {
		$known = [];
		foreach ($items->findByCollection($userId, $service, $collection) as $item) {
			$known[$item->getRemoteId()] = $item;
		}
		return $known;
	}

	/**
	 * @param array<string, Item> $known updated in place
	 */
	private function record(ItemMapper $items, array &$known, string $userId, string $service, string $collection, string $path, string $etag, ?string $localRef, ?int $container, ?string $localPath, int $now): void {
		$key = ItemMapper::key($path);
		$item = $known[$key] ?? new Item();
		$item->setUserId($userId);
		$item->setService($service);
		$item->setCollection($collection);
		$item->setRemoteId($key);
		$item->setRemoteEtag($etag);
		$item->setLocalRef($localRef);
		$item->setLocalContainer($container === null ? null : (string)$container);
		$item->setLocalPath($localPath);
		$item->setUpdatedAt($now);
		$known[$key] = $items->save($item);
	}

	/**
	 * Last path segment with the expected extension, used as the Nextcloud object URI.
	 */
	private static function objectUri(string $path, string $extension): string {
		$name = basename(rtrim($path, '/'));
		if ($name === '' || strlen($name) > 200) {
			$name = sha1($path);
		}
		return str_ends_with(strtolower($name), $extension) ? $name : $name . $extension;
	}
}
