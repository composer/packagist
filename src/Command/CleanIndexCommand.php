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

use App\Search\PackageIndex;
use App\Service\Locker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class CleanIndexCommand extends Command
{
    public function __construct(
        private PackageIndex $packageIndex,
        private Locker $locker,
        private EntityManagerInterface $doctrine,
        private string $cacheDir,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:clean-index')
            ->setDefinition([])
            ->setDescription('Cleans up the Algolia index of stale virtual packages')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = $input->getOption('verbose');

        $deployLock = $this->cacheDir.'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }

            return 0;
        }

        $lockAcquired = $this->locker->lockCommand(__CLASS__);
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }

            return 0;
        }

        // Browsing uses a cursor, so deleting records while iterating cannot make it skip any -- the
        // old page-by-page loop drifted every time it deleted something, and ran into
        // paginationLimitedTo after 3 pages.
        /** @var iterable<array{objectID: string, name: string, type: string}> $records */
        $records = $this->packageIndex->browse(['filters' => 'type:"virtual-package" AND trendiness=100']);

        foreach ($records as $record) {
            // Deleting is driven by this loop, so it does not rely on the filters above having been
            // applied: anything that is not a stale virtual package is left alone regardless.
            if (($record['type'] ?? null) !== 'virtual-package') {
                continue;
            }

            $objectId = $record['objectID'];

            if (!str_starts_with($objectId, 'virtual:')) {
                /** @var array{hits: list<array{objectID: string}>} $duplicate */
                $duplicate = $this->packageIndex->search(['query' => '', 'facetFilters' => ['objectID:virtual:'.$objectId]]);
                if (\count($duplicate['hits']) === 1) {
                    if ($verbose) {
                        $output->writeln('Deleting '.$objectId.' which is a duplicate of '.$duplicate['hits'][0]['objectID']);
                    }
                    $this->packageIndex->deleteRecord($objectId);
                    continue;
                }
            }

            if (!$this->hasProviders($record['name'])) {
                if ($verbose) {
                    $output->writeln('Deleting '.$objectId.' which has no provider anymore');
                }
                $this->packageIndex->deleteRecord($objectId);
            }
        }

        $this->locker->unlockCommand(__CLASS__);

        return 0;
    }

    private function hasProviders(string $provided): bool
    {
        return (bool) $this->doctrine->getConnection()->fetchOne(
            'SELECT COUNT(p.id) as count
                FROM package p
                JOIN package_version pv ON p.id = pv.package_id
                JOIN link_provide lp ON lp.version_id = pv.id
                WHERE pv.development = true
                AND lp.packageName = :provided',
            ['provided' => $provided]
        );
    }
}
