<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync;

interface SyncEngine {
	/**
	 * Advance the service's sync. Engines save progress through the context
	 * as they go, so an interrupted run resumes where it stopped.
	 *
	 * @return bool true when the service is in sync, false when the budget ran out first
	 */
	public function run(SyncContext $context): bool;
}
