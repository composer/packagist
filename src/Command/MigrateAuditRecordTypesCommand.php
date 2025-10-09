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

use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class MigrateAuditRecordTypesCommand extends Command
{
    use DoctrineTrait;

    private const array TYPE_MAPPING = [
        'canonical_url_change' => 'canonical_url_changed',
        'version_reference_change' => 'version_reference_changed',
    ];

    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:migrate-audit-record-types')
            ->setDescription('Migrate audit record type values to new naming convention')
            ->setDefinition([
            ])
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $connection = $this->getEM()->getConnection();

        $totalUpdated = 0;

        foreach (self::TYPE_MAPPING as $oldType => $newType) {
            $output->writeln("Migrating '$oldType' to '$newType'...");

            try {
                $updatedRows = $connection->executeStatement(
                    'UPDATE audit_log SET type = ? WHERE type = ?',
                    [$newType, $oldType]
                );

                $totalUpdated += $updatedRows;

                if ($updatedRows === 0) {
                    $output->writeln("  No records found for type '$oldType'");
                } else {
                    $output->writeln("  Updated $updatedRows records");
                }
            } catch (\Exception $e) {
                $output->writeln("<error>Failed to update records for type '$oldType': {$e->getMessage()}</error>");
                return Command::FAILURE;
            }
        }

        $output->writeln("<info>Migration completed successfully. Total records updated: {$totalUpdated}</info>");

        return Command::SUCCESS;
    }
}
