<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync;

use OCA\AppleSync\Db\SyncState;

/**
 * What an engine gets for one run: the user, its sync state, the budget, and
 * a way to save progress so an interrupted run resumes where it stopped.
 */
final class SyncContext {
	/** @var list<string> */
	private array $warnings = [];
	private int $skipped = 0;

	/**
	 * @param \Closure(SyncState): void $persist saves the state and keeps the run's lock alive
	 */
	public function __construct(
		public readonly string $userId,
		public readonly SyncState $state,
		private readonly SyncBudget $budget,
		private readonly \Closure $persist,
	) {
	}

	public function cursor(): array {
		return $this->state->getCursorArray();
	}

	public function saveCursor(?array $cursor): void {
		$this->state->setCursorArray($cursor);
		($this->persist)($this->state);
	}

	/**
	 * True once the run used up its time or byte budget. Engines check this
	 * between items and save their cursor before returning false.
	 */
	public function shouldStop(): bool {
		return $this->budget->exhausted();
	}

	public function fits(int $bytes): bool {
		return $this->budget->fits($bytes);
	}

	public function countItem(int $n = 1): void {
		$this->state->setItemsDone($this->state->getItemsDone() + $n);
	}

	public function countBytes(int $bytes): void {
		$this->budget->addBytes($bytes);
		$this->state->setBytesDone($this->state->getBytesDone() + $bytes);
	}

	/**
	 * Record an item that could not be synced without failing the whole run.
	 */
	public function skip(string $reason): void {
		$this->skipped++;
		if (count($this->warnings) < 3 && !in_array($reason, $this->warnings, true)) {
			$this->warnings[] = $reason;
		}
	}

	public function skipped(): int {
		return $this->skipped;
	}

	/**
	 * @return list<string>
	 */
	public function warnings(): array {
		return $this->warnings;
	}

	public function mirrorDeletions(): bool {
		return $this->state->mirrorsDeletions();
	}

	public function target(): string {
		return (string)$this->state->getTargetOrDefault();
	}
}
