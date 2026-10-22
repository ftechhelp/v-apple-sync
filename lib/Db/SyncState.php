<?php

declare(strict_types=1);

namespace OCA\AppleSync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Per-user, per-service sync state. syncCursor is opaque to everything but the
 * owning sync engine (CloudKit syncToken, DAV sync-token, Drive walk state...).
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getService()
 * @method void setService(string $service)
 * @method bool|null getEnabled()
 * @method void setEnabled(?bool $enabled)
 * @method string|null getTarget()
 * @method void setTarget(?string $target)
 * @method string|null getOptions()
 * @method void setOptions(?string $options)
 * @method string|null getSyncCursor()
 * @method void setSyncCursor(?string $syncCursor)
 * @method string getStatus()
 * @method void setStatus(string $status)
 * @method int getLockedUntil()
 * @method void setLockedUntil(int $lockedUntil)
 * @method int getNextRunAt()
 * @method void setNextRunAt(int $nextRunAt)
 * @method int|null getLastSuccessAt()
 * @method void setLastSuccessAt(?int $lastSuccessAt)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method int getFailCount()
 * @method void setFailCount(int $failCount)
 * @method int getItemsDone()
 * @method void setItemsDone(int $itemsDone)
 * @method int getBytesDone()
 * @method void setBytesDone(int $bytesDone)
 */
class SyncState extends Entity {
	public const SERVICE_CONTACTS = 'contacts';
	public const SERVICE_CALENDARS = 'calendars';
	public const SERVICE_DRIVE = 'drive';
	public const SERVICE_PHOTOS = 'photos';
	public const SERVICE_REMINDERS = 'reminders';

	public const SERVICES = [
		self::SERVICE_CONTACTS,
		self::SERVICE_CALENDARS,
		self::SERVICE_DRIVE,
		self::SERVICE_PHOTOS,
		self::SERVICE_REMINDERS,
	];

	/** In sync; the next run is at next_run_at. */
	public const STATUS_IDLE = 'idle';
	/** A pass ran out of budget and continues on the next run. */
	public const STATUS_PENDING = 'pending';
	public const STATUS_ERROR = 'error';

	/** Services that use the CardDAV/CalDAV app-specific password; the rest use the web sign-in. */
	public const DAV_SERVICES = [self::SERVICE_CONTACTS, self::SERVICE_CALENDARS];

	/** Services that can be turned on in this version. */
	public const AVAILABLE_SERVICES = [
		self::SERVICE_CONTACTS,
		self::SERVICE_CALENDARS,
		self::SERVICE_DRIVE,
		self::SERVICE_PHOTOS,
	];

	/** Seconds between the starts of successful syncs: every cron tick (5 minutes). */
	public const INTERVALS = [
		self::SERVICE_CONTACTS => 5 * 60,
		self::SERVICE_CALENDARS => 5 * 60,
		self::SERVICE_DRIVE => 5 * 60,
		self::SERVICE_PHOTOS => 5 * 60,
		self::SERVICE_REMINDERS => 5 * 60,
	];

	/** Default target folder for file-based services. */
	public const DEFAULT_TARGETS = [
		self::SERVICE_DRIVE => '/iCloud Drive',
		self::SERVICE_PHOTOS => '/iCloud Photos',
	];

	protected $userId;
	protected $service;
	protected $enabled = false;
	protected $target;
	protected $options;
	protected $syncCursor;
	protected $status = self::STATUS_IDLE;
	protected $lockedUntil = 0;
	protected $nextRunAt = 0;
	protected $lastSuccessAt;
	protected $lastError;
	protected $failCount = 0;
	protected $itemsDone = 0;
	protected $bytesDone = 0;

	public function __construct() {
		$this->addType('enabled', 'boolean');
		$this->addType('lockedUntil', 'integer');
		$this->addType('nextRunAt', 'integer');
		$this->addType('lastSuccessAt', 'integer');
		$this->addType('failCount', 'integer');
		$this->addType('itemsDone', 'integer');
		$this->addType('bytesDone', 'integer');
	}

	public function usesDav(): bool {
		return in_array($this->service, self::DAV_SERVICES, true);
	}

	public function getTargetOrDefault(): ?string {
		return $this->target ?? self::DEFAULT_TARGETS[$this->service] ?? null;
	}

	public function mirrorsDeletions(): bool {
		return (bool)($this->getOptionsArray()['mirrorDeletions'] ?? false);
	}

	public function getCursorArray(): array {
		$decoded = json_decode($this->syncCursor ?? '', true);
		return is_array($decoded) ? $decoded : [];
	}

	public function setCursorArray(?array $cursor): void {
		$this->setSyncCursor($cursor === null ? null : json_encode($cursor, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
	}

	public function getOptionsArray(): array {
		$decoded = json_decode($this->options ?? '', true);
		return is_array($decoded) ? $decoded : [];
	}

	public function setOptionsArray(array $options): void {
		$this->setOptions(json_encode($options, JSON_THROW_ON_ERROR));
	}
}
