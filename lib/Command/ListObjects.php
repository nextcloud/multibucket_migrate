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
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class ListObjects extends Base {
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
			->setName('multibucket_migrate:list')
			->setDescription('List all objects owned by a user')
			->addArgument("user_id", InputArgument::REQUIRED, "Id of the user to list objects for")
			->addOption("count", "c", InputOption::VALUE_NONE, "Only return the number of objects instead of listing them all");
		parent::configure();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument("user_id");
		$count = $input->getOption("count");
		$user = $this->userManager->get($userId);
		if (!$user) {
			$output->writeln("<error>Uknown user $userId</error>");
			return 1;
		}

		if ($count) {
			$output->writeln($this->migrator->countObjects($user));
		} else {
			foreach ($this->migrator->listObjects($user) as $urn) {
				$output->writeln($urn);
			}
		}

		return 0;
	}
}
