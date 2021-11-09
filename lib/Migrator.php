<?php

declare(strict_types=1);
/**
 * @copyright Copyright (c) 2021 Robin Appelman <robin@icewind.nl>
 *
 * @license GNU AGPL version 3 or any later version
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License as
 * published by the Free Software Foundation, either version 3 of the
 * License, or (at your option) any later version.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License
 * along with this program.  If not, see <http://www.gnu.org/licenses/>.
 *
 */

namespace OCA\MultiBucketMigrate;

use OC\Files\FileInfo;
use OC\Files\Mount\ObjectHomeMountProvider;
use OC\Files\ObjectStore\ObjectStoreStorage;
use OC\Files\ObjectStore\S3;
use OC\Files\Storage\StorageFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;

class Migrator {
	/** @var IConfig */
	private $config;
	/** @var ObjectHomeMountProvider */
	private $mountProvider;
	/** @var IDBConnection */
	private $connection;
	/** @var IMimeTypeLoader */
	private $mimeTypeLoader;

	public function __construct(IConfig $config, ObjectHomeMountProvider $mountProvider, IDBConnection $connection, IMimeTypeLoader $mimeTypeLoader) {
		$this->config = $config;
		$this->mountProvider = $mountProvider;
		$this->connection = $connection;
		$this->mimeTypeLoader = $mimeTypeLoader;
	}

	public function isMultiBucket(): bool {
		$config = $this->config->getSystemValue('objectstore_multibucket');
		return is_array($config);
	}

	public function getCurrentBucket(IUser $user): string {
		return $this->config->getUserValue($user->getUID(), "homeobjectstore", "bucket");
	}

	public function getUsersForBucket(string $bucket): array {
		return $this->config->getUsersForUserValue("homeobjectstore", "bucket", $bucket);
	}

	/**
	 * @param IUser $user
	 * @return string[]
	 */
	public function listObjects(IUser $user): array {
		$storageFactory = new StorageFactory();
		$homeMount = $this->mountProvider->getHomeMountForUser($user, $storageFactory);
		if ($homeMount === null) {
			throw new \Exception("Nextcloud is not using an object store as primary storage");
		}
		/** @var ObjectStoreStorage $homeStorage */
		$homeStorage = $homeMount->getStorage();
		$homeCache = $homeMount->getStorage()->getCache();
		$fileIds = $this->getFileIds($homeCache->getNumericStorageId());

		return array_map(function (int $fileId) use ($homeStorage) {
			return $homeStorage->getURN($fileId);
		}, $fileIds);
	}

	public function moveUser(IUser $user, string $targetBucket, callable $progress = null) {
		$currentBucket = $this->getCurrentBucket($user);
		if ($currentBucket === $targetBucket) {
			throw new \Exception("User " . $user->getUID() . " already used bucket " . $targetBucket);
		}
		$storageFactory = new StorageFactory();

		$homeMount = $this->mountProvider->getHomeMountForUser($user, $storageFactory);
		/** @var ObjectStoreStorage $homeStorage */
		$homeStorage = $homeMount->getStorage();
		$homeCache = $homeMount->getStorage()->getCache();
		$objectStore = $homeStorage->getObjectStore();
		if (!$objectStore instanceof S3) {
			throw new \Exception("Migrating is only supported for s3 object storage");
		}
		$s3 = $objectStore->getConnection();

		if (!$s3->doesBucketExist($targetBucket)) {
			if ($progress) {
				$progress('create', 0);
			}
			$s3->createBucket(['Bucket' => $targetBucket]);
		}

		$fileIds = $this->getFileIds($homeCache->getNumericStorageId());
		if ($progress) {
			$progress('count', count($fileIds));
		}

		foreach ($fileIds as $fileId) {
			if ($progress) {
				$progress('copy', $fileId);
			}
			$key = $homeStorage->getURN($fileId);
			$s3->copy($currentBucket, $key, $targetBucket, $key);
		}

		$progress('config', 0);

		$this->config->setUserValue($user->getUID(), "homeobjectstore", "bucket", $targetBucket);

		foreach ($fileIds as $fileId) {
			if ($progress) {
				$progress('delete', $fileId);
			}
			$key = $homeStorage->getURN($fileId);
			$s3->deleteObject([
				'Bucket' => $currentBucket,
				'Key' => $key,
			]);
		}

		$progress('done', 0);
	}

	private function getFileIds(int $storageId) {
		$folderMimetype = $this->mimeTypeLoader->getId(FileInfo::MIMETYPE_FOLDER);
		$query = $this->connection->getQueryBuilder();
		$query->select('fileid')
			->from('filecache')
			->where($query->expr()->eq('storage', $query->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('mimetype', $query->createNamedParameter($folderMimetype, IQueryBuilder::PARAM_INT)));

		$result = $query->execute();
		$files = $result->fetchAll(\PDO::FETCH_COLUMN);

		return array_map(function ($id) {
			return (int)$id;
		}, $files);
	}
}
