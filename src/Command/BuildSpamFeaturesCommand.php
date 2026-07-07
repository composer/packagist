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

use App\Service\Spam\FeatureExtractor;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Builds the labelled training dataset for the spam classifier by turning packages into token
 * lists via FeatureExtractor (the same tokenizer used at inference time, so training and serving
 * can never drift). Emits two JSONL files consumed by scripts/spam/train.py:
 *
 *   metadata.jsonl - one line per package: {"label": 0|1, "tokens": [...]} (name + description + tags)
 *   readme.jsonl   - one line per package that HAS a readme (name/description-independent pass)
 *
 * Labels: 1 = spam (package.frozen = 'spam'), 0 = safe (package under a vendor.verified = 1 vendor).
 * Run against a local copy of the prod DB (see scripts/sync-mysql.sh).
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class BuildSpamFeaturesCommand extends Command
{
    use \App\Util\DoctrineTrait;

    private const BATCH_SIZE = 500;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly FeatureExtractor $featureExtractor,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:spam:build-features')
            ->setDefinition([
                new InputOption('output-dir', null, InputOption::VALUE_REQUIRED, 'Directory to write metadata.jsonl and readme.jsonl into', '.'),
            ])
            ->setDescription('Builds the labelled spam/safe training dataset (token lists) for scripts/spam/train.py')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $outputDir = (string) $input->getOption('output-dir');
        if (!is_dir($outputDir) && !mkdir($outputDir, 0777, true) && !is_dir($outputDir)) {
            $output->writeln('<error>Could not create output directory '.$outputDir.'</error>');

            return Command::FAILURE;
        }

        $metadataHandle = fopen($outputDir.'/metadata.jsonl', 'w');
        $readmeHandle = fopen($outputDir.'/readme.jsonl', 'w');
        if ($metadataHandle === false || $readmeHandle === false) {
            $output->writeln('<error>Could not open output files in '.$outputDir.'</error>');

            return Command::FAILURE;
        }

        try {
            // label 1 = spam
            $spamCounts = $this->processClass(
                1,
                'SELECT id FROM package WHERE frozen = :frozen',
                ['frozen' => 'spam'],
                $metadataHandle,
                $readmeHandle,
                $output,
            );

            // label 0 = safe (packages whose vendor is verified)
            $safeCounts = $this->processClass(
                0,
                'SELECT p.id FROM package p JOIN vendor v ON v.name = p.vendor WHERE v.verified = 1 AND p.frozen IS NULL',
                [],
                $metadataHandle,
                $readmeHandle,
                $output,
            );
        } finally {
            fclose($metadataHandle);
            fclose($readmeHandle);
        }

        $output->writeln('');
        $output->writeln('Wrote to <info>'.$outputDir.'</info>:');
        $output->writeln(sprintf('  metadata.jsonl: %d spam, %d safe', $spamCounts['metadata'], $safeCounts['metadata']));
        $output->writeln(sprintf('  readme.jsonl:   %d spam, %d safe (packages that still have a README)', $spamCounts['readme'], $safeCounts['readme']));

        return Command::SUCCESS;
    }

    /**
     * @param array<string, string> $idParams
     * @param resource              $metadataHandle
     * @param resource              $readmeHandle
     *
     * @return array{metadata: int, readme: int} number of rows emitted to each file for this label
     */
    private function processClass(int $label, string $idSql, array $idParams, $metadataHandle, $readmeHandle, OutputInterface $output): array
    {
        $conn = $this->getEM()->getConnection();

        /** @var list<int> $ids */
        $ids = array_map('intval', $conn->fetchFirstColumn($idSql, $idParams));
        $output->writeln(sprintf('Processing %d packages for label %d ...', \count($ids), $label));

        $metadataCount = 0;
        $readmeCount = 0;

        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            $rows = $conn->fetchAllAssociative(
                'SELECT id, name, description FROM package WHERE id IN (:ids)',
                ['ids' => $batch],
                ['ids' => ArrayParameterType::INTEGER],
            );

            $packageRepo = $this->getEM()->getRepository(\App\Entity\Package::class);
            $tagsById = $packageRepo->getTagsByPackageIds($batch);
            $readmesById = $packageRepo->getReadmeContentsByPackageIds($batch);

            foreach ($rows as $row) {
                $id = (int) $row['id'];
                $name = (string) $row['name'];
                $description = $row['description'] !== null ? (string) $row['description'] : null;
                $tags = $tagsById[$id] ?? [];

                $this->writeLine($metadataHandle, $label, $this->featureExtractor->metadataTokens($name, $description, $tags));
                $metadataCount++;

                if (isset($readmesById[$id]) && $readmesById[$id] !== '') {
                    $this->writeLine($readmeHandle, $label, $this->featureExtractor->readmeTokens($readmesById[$id]));
                    $readmeCount++;
                }
            }
        }

        return ['metadata' => $metadataCount, 'readme' => $readmeCount];
    }

    /**
     * @param resource     $handle
     * @param list<string> $tokens
     */
    private function writeLine($handle, int $label, array $tokens): void
    {
        fwrite($handle, json_encode(['label' => $label, 'tokens' => $tokens], JSON_THROW_ON_ERROR)."\n");
    }
}
