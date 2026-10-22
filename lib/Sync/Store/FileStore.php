<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Store;

/**
 * Where synced files go in Nextcloud. Paths are relative to the user's home
 * folder and start with a slash ("/iCloud Drive/Notes/todo.txt").
 */
interface FileStore {
	/**
	 * Create the folder and its parents if needed.
	 *
	 * @return int file id of the folder
	 */
	public function ensureFolder(string $userId, string $path): int;

	/**
	 * Write a file, replacing an existing one (Nextcloud keeps the old
	 * content as a version). A partly written new file is removed on failure.
	 *
	 * @param resource|string $content
	 * @param int|null $mtime modification time to set (seconds)
	 * @return array{id: int, size: int}
	 */
	public function write(string $userId, string $path, $content, ?int $mtime = null): array;

	/**
	 * Current path of a file or folder, or null if it no longer exists.
	 */
	public function pathOf(string $userId, int $id): ?string;

	/**
	 * Id and size of the file or folder at a path, or null.
	 *
	 * @return array{id: int, size: int}|null
	 */
	public function infoAt(string $userId, string $path): ?array;

	/**
	 * Move a file or folder. Returns false when something already exists at
	 * the target or the source is gone.
	 */
	public function move(string $userId, int $id, string $path): bool;

	/**
	 * Move a file or folder to the trash bin. Folders are only deleted when empty
	 * unless $recursive is set.
	 */
	public function delete(string $userId, int $id, bool $recursive = false): void;

	/**
	 * A name Nextcloud accepts for $name, or null if there is none.
	 */
	public function sanitizeName(string $name): ?string;
}
