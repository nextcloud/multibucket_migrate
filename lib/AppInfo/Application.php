<?php

namespace OCA\MultiBucketMigrate\AppInfo;

use OC\Files\Filesystem;
use OC\Files\Storage\Wrapper\PermissionsMask;
use OCA\Files_Sharing\SharedStorage;
use OCP\AppFramework\App;
use OCP\Constants;
use OCP\Files\Storage\IStorage;
use OCP\Util;

class Application extends App {
	public function __construct(array $urlParams = []) {
		parent::__construct('multibucket_migrate', $urlParams);

		Util::connectHook('OC_Filesystem', 'preSetup', $this, 'addStorageWrapper');
	}

	public function addStorageWrapper() {
		Filesystem::addStorageWrapper('disable_user_readonly', function ($mountPoint, IStorage $storage) {
			if ($storage->instanceOfStorage(SharedStorage::class)) {
				$userManager = \OC::$server->getUserManager();
				/** @var SharedStorage $storage */
				$userId = $storage->getShare()->getShareOwner();
				$user = $userManager->get($userId);
				if ($user && !$user->isEnabled()) {
					return new PermissionsMask([
						'storage' => $storage,
						'mask' => Constants::PERMISSION_READ + Constants::PERMISSION_SHARE,
					]);
				}
			}
			return $storage;
		});
	}
}
