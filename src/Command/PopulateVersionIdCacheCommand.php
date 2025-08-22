<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Command;

use App\Model\VersionIdCache;
use App\Service\Locker;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PopulateVersionIdCacheCommand extends Command
{
    use \App\Util\DoctrineTrait;

    public function __construct(
        private Locker $locker,
        private ManagerRegistry $doctrine,
        private VersionIdCache $versionIdCache,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:populate:version-id-cache')
            ->setDefinition([])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $conn = $this->getEM()->getConnection();

        // fetch existing ids
        $ids = $conn->fetchFirstColumn('SELECT id FROM package ORDER BY id ASC');
        /** @var int[] $ids */
        $ids = array_map('intval', $ids);

        $total = \count($ids);
        $done = 0;
        while ($id = array_shift($ids)) {
            if (!$this->locker->lockPackageUpdate($id)) {
                $ids[] = $id;
                continue;
            }

            if ((++$done % 1000) === 0) {
                $output->writeln($done.' / '.$total);
            }

            try {
                /** @var array<array{id: string, name: string, normalizedVersion: string}> $versionIds */
                $versionIds = $conn->fetchAllAssociative('SELECT id, name, normalizedVersion FROM package_version WHERE package_id = :id', ['id' => $id]);
                foreach ($versionIds as $version) {
                    $this->versionIdCache->insertVersionRaw($id, $version['name'], (int) $version['id'], $version['normalizedVersion']);
                }
            } finally {
                $this->locker->unlockPackageUpdate($id);
            }
        }

        return 0;
    }
}
