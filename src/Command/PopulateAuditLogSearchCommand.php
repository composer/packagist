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

use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Backfills / repairs the audit_log_search index from existing audit_log records.
 *
 * Reuses the exact runtime extraction ({@see AuditRecord::getSearchTerms()} via
 * {@see AuditRecordRepository::indexSearchTerms()}) so backfilled rows match live writes, and is
 * idempotent (INSERT IGNORE), so it is safe to re-run and to run while the app is live.
 *
 * Use --from-date to reindex only recent records — either to periodically re-assert index integrity
 * for newer entries, or to resume a run that died (pass the last datetime it printed).
 */
class PopulateAuditLogSearchCommand extends Command
{
    use \App\Util\DoctrineTrait;

    private const BATCH_SIZE = 500;

    public function __construct(
        private ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:populate:audit-log-search')
            ->setDescription('Backfills the audit_log_search index from existing audit_log records')
            ->addOption(
                'from-date',
                null,
                InputOption::VALUE_REQUIRED,
                'Only reindex records with datetime on or after this date (any format understood by DateTimeImmutable, e.g. "2026-06-01 12:00:00"). The command prints the datetime it last processed, so pass that value here to resume a run that died.',
            )
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $fromDate = null;
        $fromDateInput = $input->getOption('from-date');
        if (\is_string($fromDateInput) && $fromDateInput !== '') {
            try {
                $fromDate = new \DateTimeImmutable($fromDateInput);
            } catch (\Exception $e) {
                $output->writeln('<error>Invalid --from-date: '.$e->getMessage().'</error>');

                return Command::INVALID;
            }
        }

        $em = $this->getEM();
        /** @var AuditRecordRepository $repo */
        $repo = $em->getRepository(AuditRecord::class);

        $total = (int) $this->baseQuery($repo, $fromDate)
            ->select('COUNT(a.id)')
            ->getQuery()
            ->getSingleScalarResult();

        $output->writeln(\sprintf(
            'Backfilling search index for %d audit records%s',
            $total,
            $fromDate !== null ? ' from '.$fromDate->format('Y-m-d H:i:s') : '',
        ));

        $lastId = null;
        $done = 0;

        do {
            $qb = $this->baseQuery($repo, $fromDate)
                ->orderBy('a.id', 'ASC')
                ->setMaxResults(self::BATCH_SIZE);
            if ($lastId !== null) {
                $qb->andWhere('a.id > :lastId')->setParameter('lastId', $lastId, UlidType::NAME);
            }

            /** @var list<AuditRecord> $records */
            $records = $qb->getQuery()->getResult();

            $lastDatetime = null;
            foreach ($records as $record) {
                $repo->indexSearchTerms($record);
                $lastId = $record->id;
                $lastDatetime = $record->datetime;
                $done++;
            }

            $em->clear();

            if ($lastDatetime !== null) {
                // Print the last datetime processed so a died run can be resumed with --from-date
                $output->writeln(\sprintf('%d / %d processed (last datetime: %s)', $done, $total, $lastDatetime->format('Y-m-d H:i:s')));
            }
        } while (\count($records) === self::BATCH_SIZE);

        $output->writeln('Done');

        return Command::SUCCESS;
    }

    private function baseQuery(AuditRecordRepository $repo, ?\DateTimeImmutable $fromDate): \Doctrine\ORM\QueryBuilder
    {
        $qb = $repo->createQueryBuilder('a');
        if ($fromDate !== null) {
            $qb->andWhere('a.datetime >= :fromDate')->setParameter('fromDate', $fromDate, Types::DATETIME_IMMUTABLE);
        }

        return $qb;
    }
}
