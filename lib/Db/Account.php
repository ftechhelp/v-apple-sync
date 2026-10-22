<?php

declare(strict_types=1);

namespace OCA\AppleSync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * One linked Apple ID per Nextcloud user. All *Enc fields are ICrypto ciphertext.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string|null getAppleId()
 * @method void setAppleId(?string $appleId)
 * @method string getWebState()
 * @method void setWebState(string $webState)
 * @method string getDavState()
 * @method void setDavState(string $davState)
 * @method string|null getDsid()
 * @method void setDsid(?string $dsid)
 * @method int|null getTrustExpiresAt()
 * @method void setTrustExpiresAt(?int $trustExpiresAt)
 * @method int|null getLastAuthAt()
 * @method void setLastAuthAt(?int $lastAuthAt)
 * @method string|null getLastError()
 * @method void setLastError(?string $lastError)
 * @method string|null getPasswordEnc()
 * @method void setPasswordEnc(?string $passwordEnc)
 * @method string|null getAppPasswordEnc()
 * @method void setAppPasswordEnc(?string $appPasswordEnc)
 * @method string|null getWebSessionEnc()
 * @method void setWebSessionEnc(?string $webSessionEnc)
 * @method string|null getPendingAuthEnc()
 * @method void setPendingAuthEnc(?string $pendingAuthEnc)
 * @method string|null getDavAppleId()
 * @method void setDavAppleId(?string $davAppleId)
 */
class Account extends Entity {
	public const WEB_DISCONNECTED = 'disconnected';
	public const WEB_NEEDS_2FA = 'needs_2fa';
	public const WEB_CONNECTED = 'connected';
	public const WEB_REAUTH_REQUIRED = 'reauth_required';
	public const WEB_ERROR = 'error';

	public const DAV_DISCONNECTED = 'disconnected';
	public const DAV_CONNECTED = 'connected';
	/** Apple rejected the app-specific password during a sync. */
	public const DAV_ERROR = 'error';

	protected $userId;
	protected $appleId;
	protected $webState = self::WEB_DISCONNECTED;
	protected $davState = self::DAV_DISCONNECTED;
	protected $dsid;
	protected $trustExpiresAt;
	protected $lastAuthAt;
	protected $lastError;
	protected $passwordEnc;
	protected $appPasswordEnc;
	protected $webSessionEnc;
	protected $pendingAuthEnc;
	protected $davAppleId;

	public function __construct() {
		$this->addType('trustExpiresAt', 'integer');
		$this->addType('lastAuthAt', 'integer');
	}
}
