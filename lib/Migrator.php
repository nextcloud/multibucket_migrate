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

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Promise\EachPromise;
use GuzzleHttp\Promise\Promise;
use OCP\DB\Exception;
use OCP\Files\FileInfo;
use OC\Files\Mount\ObjectHomeMountProvider;
use OC\Files\ObjectStore\ObjectStoreStorage;
use OC\Files\ObjectStore\S3;
use OC\Files\Storage\StorageFactory;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\Files\IMimeTypeLoader;
use OCP\Files\ObjectStore\IObjectStore;
use OCP\IConfig;
use OCP\IDBConnection;
use OCP\IUser;
use OCP\IUserManager;

class Migrator {
	/** @var IConfig */
	private $config;
	/** @var ObjectHomeMountProvider */
	private $mountProvider;
	/** @var IDBConnection */
	private $connection;
	/** @var IMimeTypeLoader */
	private $mimeTypeLoader;
	/** @var IUserManager */
	private $userManager;

	public function __construct(
		IConfig $config,
		ObjectHomeMountProvider $mountProvider,
		IDBConnection $connection,
		IMimeTypeLoader $mimeTypeLoader,
		IUserManager $userManager
	) {
		$this->config = $config;
		$this->mountProvider = $mountProvider;
		$this->connection = $connection;
		$this->mimeTypeLoader = $mimeTypeLoader;
		$this->userManager = $userManager;
	}

	public function isMultiBucket(): bool {
		$config = $this->config->getSystemValue('objectstore_multibucket');
		return is_array($config);
	}

	public function getCurrentBucket(IUser $user): string {
		return $this->config->getUserValue($user->getUID(), "homeobjectstore", "bucket");
	}

	public function getUsersForBucket(string $bucket): array {
		$userIds = $this->config->getUsersForUserValue("homeobjectstore", "bucket", $bucket);
		$users = array_map(function (string $user) {
			return $this->userManager->get($user);
		}, $userIds);
		$normalizedUsers = array_map(function (IUser $user) {
			return $user->getUID();
		}, array_filter($users));
		return array_unique($normalizedUsers);
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
		$homeCache = $homeMount->getStorage()->getCache();
		$fileIds = $this->getFileIds($homeCache->getNumericStorageId());

		return array_map(function (int $fileId) {
			return 'urn:oid:' . $fileId;
		}, $fileIds);
	}

	public function countObjects(IUser $user): int {
		$storageFactory = new StorageFactory();
		$homeMount = $this->mountProvider->getHomeMountForUser($user, $storageFactory);
		if ($homeMount === null) {
			throw new \Exception("Nextcloud is not using an object store as primary storage");
		}
		$homeCache = $homeMount->getStorage()->getCache();
		return $this->countFiles($homeCache->getNumericStorageId());
	}

	private function getObjectStorage(ObjectStoreStorage $storage): IObjectStore {
		if (method_exists($storage, 'getObjectStore')) {
			return $storage->getObjectStore();
		} else {
			// workaround for pre nc17
			$class = new \ReflectionClass($storage);
			$property = $class->getProperty('objectStore');
			$property->setAccessible(true);
			return $property->getValue($storage);
		}
	}

	private function getS3Connection(S3 $s3): S3Client {
		/** @psalm-suppress RedundantCondition */
		if (is_callable([$s3, 'getConnection'])) {
			return $s3->getConnection();
		} else {
			// workaround for pre nc17
			$class = new \ReflectionClass($s3);
			$method = $class->getMethod('getConnection');
			$method->setAccessible(true);
			return $method->invoke($s3);
		}
	}

	public function moveUser(IUser $user, string $targetBucket, int $parallel, int $maxAllowedItems, callable $progress) {
		$currentBucket = $this->getCurrentBucket($user);
		if ($currentBucket === $targetBucket) {
			throw new \Exception("User " . $user->getUID() . " already used bucket " . $targetBucket);
		}
		$storageFactory = new StorageFactory();

		$homeMount = $this->mountProvider->getHomeMountForUser($user, $storageFactory);
		/** @var ObjectStoreStorage $homeStorage */
		$homeStorage = $homeMount->getStorage();
		$homeCache = $homeMount->getStorage()->getCache();
		$objectStore = $this->getObjectStorage($homeStorage);
		if (!$objectStore instanceof S3) {
			throw new \Exception("Migrating is only supported for s3 object storage");
		}
		$s3 = $this->getS3Connection($objectStore);

		if (!$s3->doesBucketExist($targetBucket)) {
			$progress('create', 0);
			$s3->createBucket(['Bucket' => $targetBucket]);
		}

		$fileIds = $this->getFileIds($homeCache->getNumericStorageId());

		if ($maxAllowedItems >= 0 && count($fileIds) > $maxAllowedItems) {
			$progress('max_files_reached', 0);
			throw new \Exception("User " . $user->getUID() . " has more files than the allowed to be migrated.");
		}

		$progress('count', count($fileIds));

		if ($parallel > 1) {
			$fileChunks = array_chunk($fileIds, $parallel);
			foreach ($fileChunks as $chunk) {
				$progress('copy', count($chunk));
				$promises = array_map(function ($fileId) use ($progress, $s3, $currentBucket, $targetBucket) {
					$key = 'urn:oid:' . $fileId;
					$promise = $s3->copyAsync($currentBucket, $key, $targetBucket, $key);
					return $promise->then(null, function ($e) use ($progress, $key) {
						if ($e->getStatusCode() === 404) {
							$progress('warn', "Object with key $key not found in source bucket, skipping");
						} else {
							throw $e;
						}
					});
				}, $chunk);
				$this->all($promises)->wait();
			}
		} else {
			foreach ($fileIds as $fileId) {
				$progress('copy', 1);
				$key = 'urn:oid:' . $fileId;
				try {
					$s3->copy($currentBucket, $key, $targetBucket, $key);
				} catch (S3Exception $e) {
					if ($e->getStatusCode() === 404) {
						$progress('warn', "Object with key $key not found in source bucket, skipping");
					} else {
						throw $e;
					}
				}
			}
		}

		$progress('config', 0);

		try {
			$this->setUserBucket($user, $targetBucket);
		} catch (Exception $e) {
			// since the object copies can take a long time, the database might have gone away
			$this->connection->connect();
			$this->setUserBucket($user, $targetBucket);
		}

		$fileChunks = array_chunk($fileIds, 500);
		foreach ($fileChunks as $chunk) {
			$progress('delete', count($chunk));
			$objects = array_map(function ($fileId) {
				return ['Key' => 'urn:oid:' . $fileId];
			}, $chunk);
			$s3->deleteObjects([
				'Bucket' => $currentBucket,
				'Delete' => [
					'Objects' => $objects,
				],
			]);
		}

		$progress('done', 0);
	}

	public function setUserBucket(IUser $user, string $bucket) {
		$this->config->setUserValue($user->getUID(), "homeobjectstore", "bucket", $bucket);
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

	private function countFiles(int $storageId): int {
		$folderMimetype = $this->mimeTypeLoader->getId(FileInfo::MIMETYPE_FOLDER);
		$query = $this->connection->getQueryBuilder();
		$query->select($query->func()->count('fileid'))
			->from('filecache')
			->where($query->expr()->eq('storage', $query->createNamedParameter($storageId, IQueryBuilder::PARAM_INT)))
			->andWhere($query->expr()->neq('mimetype', $query->createNamedParameter($folderMimetype, IQueryBuilder::PARAM_INT)));

		$result = $query->execute();
		return (int) $result->fetchColumn();
	}

	private function all(array $promises): Promise {
		return (new EachPromise($promises, [
			'fulfilled' => null,
			'rejected' => function ($reason, $idx, Promise $aggregate) {
				$aggregate->reject($reason);
			},
		]))->promise();
	}
}
