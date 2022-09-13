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

use Algolia\AlgoliaSearch\SearchClient;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use App\Service\Locker;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Console\Command\Command;

class CleanIndexCommand extends Command
{
    private SearchClient $algolia;
    private Locker $locker;
    private EntityManagerInterface $doctrine;
    private string $algoliaIndexName;
    private string $cacheDir;

    public function __construct(SearchClient $algolia, Locker $locker, EntityManagerInterface $doctrine, string $algoliaIndexName, string $cacheDir)
    {
        $this->algolia = $algolia;
        $this->locker = $locker;
        $this->doctrine = $doctrine;
        $this->algoliaIndexName = $algoliaIndexName;
        $this->cacheDir = $cacheDir;

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

                if (!$this->hasProviders($result['name'])) {
                    if ($verbose) {
                        $output->writeln('Deleting '.$result['objectID'].' which has no provider anymore');
                    }
                    $index->deleteObject($result['objectID']);
                }
            }
            $page++;
        } while (count($results['hits']) >= $perPage);

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
