<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync\Store;

use OCA\AppleSync\ICloud\Exception\ICloudException;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IFilenameValidator;
use OCP\Files\IRootFolder;
use OCP\Files\Node;
use OCP\Files\NotFoundException;

class NcFileStore implements FileStore {
	/** @var array<string, Folder> */
	private array $folders = [];

	public function __construct(
		private IRootFolder $rootFolder,
		private IFilenameValidator $filenameValidator,
	) {
	}

	public function ensureFolder(string $userId, string $path): int {
		return $this->folder($userId, $path)->getId();
	}

	public function write(string $userId, string $path, $content, ?int $mtime = null): array {
		$folder = $this->folder($userId, dirname($path));
		$name = basename($path);
		if ($folder->nodeExists($name)) {
			$node = $folder->get($name);
			if (!$node instanceof File) {
				throw new ICloudException(sprintf('A folder is in the way of %s', $path));
			}
			$node->putContent($content);
		} else {
			try {
				$node = $folder->newFile($name, $content);
			} catch (\Throwable $e) {
				$this->removePartial($folder, $name);
				throw $e;
			}
		}
		if ($mtime !== null) {
			$node->touch($mtime);
		}
		return ['id' => $node->getId(), 'size' => (int)$node->getSize()];
	}

	public function pathOf(string $userId, int $id): ?string {
		$node = $this->userFolder($userId)->getFirstNodeById($id);
		return $node === null ? null : $this->userFolder($userId)->getRelativePath($node->getPath());
	}

	public function infoAt(string $userId, string $path): ?array {
		try {
			$node = $this->userFolder($userId)->get(ltrim($path, '/'));
		} catch (NotFoundException) {
			return null;
		}
		return ['id' => $node->getId(), 'size' => (int)$node->getSize()];
	}

	public function move(string $userId, int $id, string $path): bool {
		$userFolder = $this->userFolder($userId);
		$node = $userFolder->getFirstNodeById($id);
		if ($node === null || $userFolder->nodeExists(ltrim($path, '/'))) {
			return false;
		}
		$parent = $this->folder($userId, dirname($path));
		$node->move($parent->getPath() . '/' . basename($path));
		return true;
	}

	public function delete(string $userId, int $id, bool $recursive = false): void {
		$node = $this->userFolder($userId)->getFirstNodeById($id);
		if ($node === null || $this->isUserRoot($userId, $node)) {
			return;
		}
		if ($node instanceof Folder && !$recursive && $node->getDirectoryListing() !== []) {
			return;
		}
		$node->delete();
		$this->folders = [];
	}

	public function sanitizeName(string $name): ?string {
		if ($name !== '' && $this->filenameValidator->isFilenameValid($name)) {
			return $name;
		}
		$clean = $this->filenameValidator->sanitizeFilename($name);
		return $clean !== '' && $this->filenameValidator->isFilenameValid($clean) ? $clean : null;
	}

	private function folder(string $userId, string $path): Folder {
		$path = '/' . trim($path, '/');
		$key = $userId . ':' . $path;
		if (isset($this->folders[$key])) {
			return $this->folders[$key];
		}
		$folder = $this->userFolder($userId);
		foreach (array_filter(explode('/', $path), static fn (string $part): bool => $part !== '') as $part) {
			if ($folder->nodeExists($part)) {
				$next = $folder->get($part);
				if (!$next instanceof Folder) {
					throw new ICloudException(sprintf('A file is in the way of the folder %s', $path));
				}
				$folder = $next;
			} else {
				$folder = $folder->newFolder($part);
			}
		}
		return $this->folders[$key] = $folder;
	}

	private function userFolder(string $userId): Folder {
		return $this->rootFolder->getUserFolder($userId);
	}

	private function isUserRoot(string $userId, Node $node): bool {
		return $node->getId() === $this->userFolder($userId)->getId();
	}

	private function removePartial(Folder $folder, string $name): void {
		try {
			if ($folder->nodeExists($name)) {
				$folder->get($name)->delete();
			}
		} catch (\Throwable) {
			// best effort; the next run overwrites it anyway
		}
	}
}
