<?php

namespace OCA\MultiBucketMigrate\AppInfo;

use OCP\AppFramework\App;

class Application extends App {
	public function __construct(array $urlParams = []) {
		parent::__construct('multibucket_migrate', $urlParams);
	}
}
