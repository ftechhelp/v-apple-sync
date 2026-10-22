<?php

declare(strict_types=1);

namespace OCA\AppleSync\Sync;

enum RunOutcome: string {
	/** In sync. */
	case Finished = 'finished';
	/** The budget ran out; the next run continues. */
	case Partial = 'partial';
	/** Another run holds the lock. */
	case Busy = 'busy';
	/** The run failed; the error is in the sync state. */
	case Failed = 'failed';
	/** Turned off, or the credentials it needs are not connected. */
	case Skipped = 'skipped';
}
