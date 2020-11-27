<?php

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

use Algolia\AlgoliaSearch\SearchClient;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Doctrine\Persistence\ManagerRegistry;
use App\Service\Locker;
use Symfony\Component\Console\Command\Command;

class CleanIndexCommand extends Command
{
    private SearchClient $algolia;
    private Locker $locker;
    private ManagerRegistry $doctrine;
    private string $algoliaIndexName;
    private string $cacheDir;

    public function __construct(SearchClient $algolia, Locker $locker, ManagerRegistry $doctrine, string $algoliaIndexName, string $cacheDir)
    {
        $this->algolia = $algolia;
        $this->locker = $locker;
        $this->doctrine = $doctrine;
        $this->algoliaIndexName = $algoliaIndexName;
        $this->cacheDir = $cacheDir;

        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:clean-index')
            ->setDefinition(array(
            ))
            ->setDescription('Cleans up the Algolia index of stale virtual packages')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $verbose = $input->getOption('verbose');

        $deployLock = $this->cacheDir.'/deploy.globallock';
        if (file_exists($deployLock)) {
            if ($verbose) {
                $output->writeln('Aborting, '.$deployLock.' file present');
            }
            return;
        }

        $lockAcquired = $this->locker->lockCommand($this->getName());
        if (!$lockAcquired) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another task is running already');
            }
            return;
        }

        $index = $this->algolia->initIndex($this->algoliaIndexName);

        $page = 0;
        $perPage = 100;
        do {
            $results = $index->search('', ['facets' => "*,type,tags", 'facetFilters' => ['type:virtual-package'], 'numericFilters' => ['trendiness=100'], 'hitsPerPage' => $perPage, 'page' => $page]);
            foreach ($results['hits'] as $result) {
                if (0 !== strpos($result['objectID'], 'virtual:')) {
                    $duplicate = $index->search('', ['facets' => "*,objectID,type,tags", 'facetFilters' => ['objectID:virtual:'.$result['objectID']]]);
                    if (count($duplicate['hits']) === 1) {
                        if ($verbose) {
                            $output->writeln('Deleting '.$result['objectID'].' which is a duplicate of '.$duplicate['hits'][0]['objectID']);
                        }
                        $index->deleteObject($result['objectID']);
                        continue;
                    }
                }

                if (!$this->hasProviders($this->doctrine, $result['name'])) {
                    if ($verbose) {
                        $output->writeln('Deleting '.$result['objectID'].' which has no provider anymore');
                    }
                    $index->deleteObject($result['objectID']);
                }
            }
            $page++;
        } while (count($results['hits']) >= $perPage);

        $this->locker->unlockCommand($this->getName());

        return 0;
    }

    private function hasProviders(ManagerRegistry $doctrine, string $provided): bool
    {
        return (bool) $doctrine->getManager()->getConnection()->fetchColumn(
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
