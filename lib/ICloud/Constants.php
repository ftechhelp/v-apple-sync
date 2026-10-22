<?php

declare(strict_types=1);

namespace OCA\AppleSync\ICloud;

/**
 * Every Apple-specific magic value lives here, so that when Apple changes the
 * (undocumented) web API there is one place to update.
 *
 * Sources: rclone backend/iclouddrive/api/session.go and the timlaing/pyicloud fork.
 */
final class Constants {
	public const AUTH_ENDPOINT = 'https://idmsa.apple.com/appleauth/auth';
	public const AUTH_ORIGIN = 'https://idmsa.apple.com';
	public const SETUP_ENDPOINT = 'https://setup.icloud.com/setup/ws/1';
	public const HOME_ENDPOINT = 'https://www.icloud.com';

	/** Used as X-Apple-Widget-Key and X-Apple-OAuth-Client-Id. */
	public const WIDGET_KEY = 'd39ba9916b7251055b22c7f910e2ea796ee65e98b2ddecea8f5dde8d9d1a815d';

	public const USER_AGENT = 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/605.1.15 (KHTML, like Gecko) Version/18.3.1 Safari/605.1.15';

	public const CLIENT_BUILD_NUMBER = '2534Project66';
	public const CLIENT_MASTERING_NUMBER = '2534B22';

	/** Apple doesn't publish this; rclone documents 30 days. */
	public const TRUST_TOKEN_LIFETIME = 30 * 24 * 3600;

	/** Wrong 2FA code. */
	public const ERROR_WRONG_CODE = -21669;

	/** CardDAV/CalDAV entry points; they redirect to the account's pNN-* partition host. */
	public const CARDDAV_ENDPOINT = 'https://contacts.icloud.com';
	public const CALDAV_ENDPOINT = 'https://caldav.icloud.com';

	/** iCloud Drive */
	public const DRIVE_ROOT = 'FOLDER::com.apple.CloudDocs::root';
	public const DRIVE_TRASH = 'FOLDER::com.apple.CloudDocs::TRASH_ROOT';

	/** iCloud Photos (CloudKit) */
	public const PHOTOS_CONTAINER = 'com.apple.photos.cloud';
	public const PHOTOS_ZONE = 'PrimarySync';
	public const PHOTOS_ALL_QUERY = 'CPLAssetAndMasterByAssetDateWithoutHiddenOrDeleted';
}
