<?php

declare(strict_types=1);

namespace OCA\AppleSync\Tests\Support;

use OCP\Http\Client\IResponse;

/**
 * IResponse for answers of the fake iCloud.
 */
final class PsrResponse implements IResponse {
	/**
	 * @param array<string, string> $headers
	 */
	public function __construct(
		private int $status,
		private array $headers,
		private string $body,
	) {
	}

	public function getBody() {
		return $this->body;
	}

	public function getStatusCode(): int {
		return $this->status;
	}

	public function getHeader(string $key): string {
		foreach ($this->headers as $name => $value) {
			if (strcasecmp($name, $key) === 0) {
				return $value;
			}
		}
		return '';
	}

	public function getHeaders(): array {
		return array_map(static fn (string $value): array => [$value], $this->headers);
	}
}
