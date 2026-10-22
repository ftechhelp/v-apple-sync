<?php

declare(strict_types=1);

namespace OCA\AppleSync\Db;

use OCP\AppFramework\Db\Entity;

/**
 * Maps one remote iCloud object to its local Nextcloud counterpart.
 *
 * @method string getUserId()
 * @method void setUserId(string $userId)
 * @method string getService()
 * @method void setService(string $service)
 * @method string getCollection()
 * @method void setCollection(string $collection)
 * @method string getRemoteId()
 * @method void setRemoteId(string $remoteId)
 * @method string|null getRemoteEtag()
 * @method void setRemoteEtag(?string $remoteEtag)
 * @method string|null getLocalRef()
 * @method void setLocalRef(?string $localRef)
 * @method string|null getLocalContainer()
 * @method void setLocalContainer(?string $localContainer)
 * @method string|null getLocalPath()
 * @method void setLocalPath(?string $localPath)
 * @method int getUpdatedAt()
 * @method void setUpdatedAt(int $updatedAt)
 */
class Item extends Entity {
	/** Collection of iCloud Drive folders; they are not counted as synced items. */
	public const COLLECTION_FOLDER = 'folder';

	protected $userId;
	protected $service;
	protected $collection = '';
	protected $remoteId;
	protected $remoteEtag;
	protected $localRef;
	protected $localContainer;
	protected $localPath;
	protected $updatedAt = 0;

	public function __construct() {
		$this->addType('updatedAt', 'integer');
	}
}
