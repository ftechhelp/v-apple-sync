<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Support;

use OCA\AppleSync\Sync\Store\FileStore;

/**
 * A user's files as path => node. Names with "/" or a leading space are invalid;
 * "bad:name" is sanitized to "bad-name".
 */
final class MemoryFileStore implements FileStore {
	/** @var array<string, array{id: int, type: string, content?: string, mtime?: ?int}> */
	public array $nodes = [];
	/** @var list<string> paths in the trash bin */
	public array $trash = [];
	public int $downloads = 0;
	private int $nextId = 100;

	public function ensureFolder(string $userId, string $path): int {
		$path = self::normalize($path);
		if ($path === '/') {
			return 1;
		}
		$this->ensureFolder($userId, dirname($path));
		if (isset($this->nodes[$path]) && $this->nodes[$path]['type'] !== 'folder') {
			throw new \RuntimeException("file in the way of $path");
		}
		$this->nodes[$path] ??= ['id' => $this->nextId++, 'type' => 'folder'];
		return $this->nodes[$path]['id'];
	}

	public function write(string $userId, string $path, $content, ?int $mtime = null): array {
		$path = self::normalize($path);
		$this->ensureFolder($userId, dirname($path));
		$data = is_resource($content) ? (string)stream_get_contents($content) : (string)$content;
		$this->downloads++;
		$id = $this->nodes[$path]['id'] ?? $this->nextId++;
		$this->nodes[$path] = ['id' => $id, 'type' => 'file', 'content' => $data, 'mtime' => $mtime];
		return ['id' => $id, 'size' => strlen($data)];
	}

	public function pathOf(string $userId, int $id): ?string {
		foreach ($this->nodes as $path => $node) {
			if ($node['id'] === $id) {
				return $path;
			}
		}
		return null;
	}

	public function infoAt(string $userId, string $path): ?array {
		$node = $this->nodes[self::normalize($path)] ?? null;
		return $node === null ? null : ['id' => $node['id'], 'size' => strlen($node['content'] ?? '')];
	}

	public function move(string $userId, int $id, string $path): bool {
		$path = self::normalize($path);
		$from = $this->pathOf($userId, $id);
		if ($from === null || isset($this->nodes[$path])) {
			return false;
		}
		$this->ensureFolder($userId, dirname($path));
		foreach ($this->nodes as $nodePath => $node) {
			if ($nodePath === $from || str_starts_with($nodePath, $from . '/')) {
				unset($this->nodes[$nodePath]);
				$this->nodes[$path . substr($nodePath, strlen($from))] = $node;
			}
		}
		return true;
	}

	public function delete(string $userId, int $id, bool $recursive = false): void {
		$path = $this->pathOf($userId, $id);
		if ($path === null) {
			return;
		}
		$children = array_filter(array_keys($this->nodes), static fn (string $p): bool => str_starts_with($p, $path . '/'));
		if ($this->nodes[$path]['type'] === 'folder' && $children !== [] && !$recursive) {
			return;
		}
		foreach ([$path, ...$children] as $p) {
			unset($this->nodes[$p]);
		}
		$this->trash[] = $path;
	}

	public function sanitizeName(string $name): ?string {
		if ($name === '' || str_contains($name, '/') || str_starts_with($name, ' ')) {
			return null;
		}
		return str_replace(':', '-', $name);
	}

	public function content(string $path): ?string {
		return $this->nodes[$path]['content'] ?? null;
	}

	/**
	 * @return list<string> file paths below a folder
	 */
	public function files(string $below): array {
		$files = [];
		foreach ($this->nodes as $path => $node) {
			if ($node['type'] === 'file' && str_starts_with($path, rtrim($below, '/') . '/')) {
				$files[] = $path;
			}
		}
		sort($files);
		return $files;
	}

	private static function normalize(string $path): string {
		return '/' . trim($path, '/');
	}
}
