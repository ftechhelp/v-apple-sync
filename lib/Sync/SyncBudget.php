<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync;

/**
 * How much one run may do before it stops and leaves the rest for the next run.
 */
final class SyncBudget {
	private int $bytes = 0;

	public function __construct(
		private readonly float $deadline,
		private readonly int $maxBytes,
	) {
	}

	public static function seconds(int $seconds, int $maxBytes): self {
		return new self(microtime(true) + $seconds, $maxBytes);
	}

	public static function unlimited(): self {
		return new self(INF, PHP_INT_MAX);
	}

	public function exhausted(): bool {
		return microtime(true) >= $this->deadline || $this->bytes >= $this->maxBytes;
	}

	public function addBytes(int $bytes): void {
		$this->bytes += $bytes;
	}

	/**
	 * Whether a download of $size bytes still fits. The first download of a
	 * run always fits, so a file larger than the byte cap is not stuck forever.
	 */
	public function fits(int $size): bool {
		return $this->bytes === 0 || $this->bytes + $size <= $this->maxBytes;
	}
}
