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

namespace OCA\MultiBucketMigrate\Command;

use OC\Core\Command\Base;
use OCA\MultiBucketMigrate\Migrator;
use OCP\IUserManager;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class MoveUser extends Base {
	/** @var Migrator */
	private $migrator;
	/** @var IUserManager */
	private $userManager;

	public function __construct(Migrator $migrator, IUserManager $userManager) {
		parent::__construct();

		$this->migrator = $migrator;
		$this->userManager = $userManager;
	}

	protected function configure() {
		$this
			->setName('multibucket_migrate:move_user')
			->setDescription('Move a user to a different backup')
			->addArgument("user_id", InputArgument::REQUIRED, "Id of the user to migrate")
			->addArgument("target_bucket", InputArgument::REQUIRED, "Bucket to migrate the user to")
			->addOption("parallel", null, InputOption::VALUE_REQUIRED, "Number of S3 copy commands to run in parallel", 1)
			->addOption("max_allowed_files", null, InputOption::VALUE_REQUIRED, "Maximum number of allowed objects to be migrated", -1)
			->addOption("restore-on-failure", null, InputOption::VALUE_NONE, "Restore and re-activate the user on errors");
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		if (!$this->migrator->isMultiBucket()) {
			$output->writeln("<error>Multibucket is not setup</error>");
			return 1;
		}

		$userId = $input->getArgument("user_id");
		$parallel = (int) $input->getOption("parallel");
		$maxAllowedFiles = (int) $input->getOption("max_allowed_files");
		$restoreOnFailure = $input->getOption("restore-on-failure");
		$maxAllowedFilesReached = false;
		$user = $this->userManager->get($userId);
		if (!$user) {
			$output->writeln("<error>Uknown user $userId</error>");
			return 1;
		}
		$targetBucket = $input->getArgument("target_bucket");

		$sourceBucket = $this->migrator->getCurrentBucket($user);
		if ($sourceBucket === $targetBucket) {
			$output->writeln("<error>User $userId is already using bucket $targetBucket</error>");
			return 1;
		}

		$count = 0;
		$state = '';
		/** @var ProgressBar|null $progressBar */
		$progressBar = null;

		try {
			$output->writeln("<info>Disabling user</info>");
			$user->setEnabled(false);
			$this->migrator->moveUser($user, $targetBucket, $parallel, $maxAllowedFiles, function (string $step, $arg) use (
				&$state,
				&$count,
				&
				$progressBar,
				&$maxAllowedFilesReached,
				$output
			) {
				if ($step === 'warn') {
					$output->writeln("\n<error>$arg</error>\n");
				}

				if ($step === 'create') {
					$output->writeln("<info>Creating target bucket</info>");
				} elseif ($step === 'count') {
					$count = $arg;
				} elseif ($step === 'max_files_reached') {
					$maxAllowedFilesReached = true;
				} elseif ($step === 'copy') {
					if ($state !== 'copy') {
						$output->writeln("<info>Copying $count objects to target bucket</info>");
						$state = 'copy';
						$progressBar = new ProgressBar($output, $count);
						$progressBar->start();
					}
					$progressBar->advance($arg);
				} elseif ($step === 'config') {
					// if the user had no files to copy, the progress bar will never be setup
					if ($progressBar) {
						$progressBar->finish();
						$progressBar = null;
					}
					$output->writeln("\n<info>Setting user to use new bucket</info>");
					$state = 'config';
				} elseif ($step === 'delete') {
					if ($state !== 'delete') {
						$output->writeln("<info>Deleting objects in old bucket</info>");
						$state = 'delete';
						$progressBar = new ProgressBar($output, $count);
						$progressBar->start();
					}
					$progressBar->advance($arg);
				} elseif ($step === 'done') {
					// if the user had no files to delete, the progress bar will never be setup
					if ($progressBar) {
						$progressBar->finish();
					}
				}
			});
			$output->writeln("\n<info>Re-enabling user</info>");
			$user->setEnabled(true);
		} catch (\Exception $e) {
			if ($maxAllowedFilesReached) {
				$output->writeln("<error>" . $e->getMessage() . "</error>");
				$output->writeln("<info>Enabling user</info>");
				$user->setEnabled(true);
				return 1;
			} elseif ($restoreOnFailure) {
				$output->writeln("<error>Error while migrating, restoring user</error>");
				$this->migrator->setUserBucket($user, $sourceBucket);
				$user->setEnabled(true);
			} else {
				$output->writeln("<error>Error while migrating, user has been left disabled</error>");
			}
			throw $e;
		}

		return 0;
	}
}
