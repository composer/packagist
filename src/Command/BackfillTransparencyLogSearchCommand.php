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

use App\Entity\PackageTransparencyLogRepository;
use App\Entity\PackageTransparencyLogSearchRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Initial backfill of the entries that were already projected  {@see \App\Entity\PackageTransparencyLogSearch}
 * existed. The projector will index new entries itself. No need to run this command if the search index is deployed at
 * the same time as the transparency log projecotr.
 *
 * Entries that are already in index are skipped.
 */
class BackfillTransparencyLogSearchCommand extends Command
{
    use \App\Util\DoctrineTrait;

    private const BATCH_SIZE = 500;

    public function __construct(
        private ManagerRegistry $doctrine,
        private PackageTransparencyLogRepository $transparencyLogRepository,
        private PackageTransparencyLogSearchRepository $transparencyLogSearchRepository,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:backfill-transparency-log-search')
            ->setDescription('Indexes the people named by transparency-log entries projected before the index existed')
            ->addOption(
                'dry-run',
                null,
                InputOption::VALUE_NONE,
                'Report how many entries would be indexed without writing anything.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $dryRun = (bool) $input->getOption('dry-run');
        if ($dryRun) {
            $output->writeln('<comment>Dry run, nothing will be written</comment>');
        }

        $em = $this->getEM();
        $after = 0;
        $indexed = 0;
        $entriesIndexed = 0;

        do {
            $entries = $this->transparencyLogRepository->findForIndexing($after, self::BATCH_SIZE);
            if ($entries === []) {
                break;
            }

            $alreadyIndexed = $this->transparencyLogSearchRepository->filterIndexedLeafIndexes(
                array_map(static fn ($entry): int => $entry->leafIndex, $entries),
            );

            $pending = [];
            foreach ($entries as $entry) {
                $after = $entry->leafIndex;

                if (\in_array($entry->leafIndex, $alreadyIndexed, true) || $entry->getSearchTerms() === []) {
                    continue;
                }

                $pending[] = $entry;
                $entriesIndexed++;
                $indexed += $dryRun ? \count($entry->getSearchTerms()) : 0;
            }

            if (!$dryRun) {
                $indexed += $this->transparencyLogSearchRepository->index($pending);
            }

            $em->clear();
            $output->writeln(\sprintf('%d row(s) from %d entry/entries so far (up to leaf %d)', $indexed, $entriesIndexed, $after));
        } while (\count($entries) === self::BATCH_SIZE);

        $output->writeln(\sprintf(
            $dryRun ? 'Done, %d row(s) would be indexed' : 'Done, %d row(s) indexed',
            $indexed,
        ));

        return Command::SUCCESS;
    }
}
