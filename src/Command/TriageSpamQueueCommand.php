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

use App\Entity\Package;
use App\Entity\PackageReadme;
use App\Entity\Vendor;
use App\Service\Locker;
use App\Service\Spam\SpamClassifier;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Automatically clears confidently-safe packages out of the /spam review queue using the trained
 * classifier, leaving anything uncertain for a human.
 *
 * Because clearing happens at the vendor level (VendorRepository::verify() verifies the vendor and
 * nulls suspect for all its packages), a vendor is only auto-cleared when EVERY one of its
 * currently-suspect packages scores as confidently safe. When no model is deployed the command is a
 * no-op. Defaults to a dry run; pass --apply to actually clear.
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class TriageSpamQueueCommand extends Command
{
    use \App\Util\DoctrineTrait;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly SpamClassifier $spamClassifier,
        private readonly Locker $locker,
        private readonly LoggerInterface $logger,
        private readonly \Graze\DogStatsD\Client $statsd,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:spam:triage-queue')
            ->setDefinition([
                new InputOption('apply', null, InputOption::VALUE_NONE, 'Actually clear (verify) the confidently-safe vendors. Without this the command only reports (dry run).'),
            ])
            ->setDescription('Auto-clears confidently-safe packages from the /spam queue using the trained classifier')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (!$this->spamClassifier->isModelAvailable()) {
            $this->logger->warning('packagist:spam:triage-queue skipped: no spam model available (SPAM_MODEL_FILE unset, missing or invalid)');
            $output->writeln('<comment>No spam model available (SPAM_MODEL_FILE unset, missing or invalid) - nothing to do.</comment>');

            return Command::SUCCESS;
        }

        if (!$this->locker->lockCommand(__CLASS__)) {
            $output->writeln('<comment>Aborting, another instance is already running.</comment>');

            return Command::SUCCESS;
        }

        $apply = (bool) $input->getOption('apply');
        $output->writeln($apply ? 'Running in <info>APPLY</info> mode - confidently-safe vendors will be verified.' : 'Running in <comment>dry-run</comment> mode - no changes will be made (use --apply to clear).');

        try {
            $em = $this->getEM();
            /** @var \App\Entity\PackageRepository $packageRepo */
            $packageRepo = $em->getRepository(Package::class);
            /** @var \App\Entity\VendorRepository $vendorRepo */
            $vendorRepo = $em->getRepository(Vendor::class);

            $packages = $packageRepo->getAllSuspectPackages();
            if (\count($packages) === 0) {
                $output->writeln('The spam queue is empty.');

                return Command::SUCCESS;
            }

            $tagsById = $this->fetchTags($packageRepo, $packages);

            // Group suspect packages by vendor (verify() acts on the whole vendor).
            $byVendor = [];
            foreach ($packages as $pkg) {
                $byVendor[$pkg['vendor']][] = $pkg;
            }

            $totalPackages = \count($packages);
            $clearedVendors = 0;
            $clearedPackages = 0;

            foreach ($byVendor as $vendor => $vendorPackages) {
                $allSafe = true;
                $lines = [];
                foreach ($vendorPackages as $pkg) {
                    $readme = $em->find(PackageReadme::class, $pkg['id']);
                    $readmeHtml = $readme?->contents;
                    $tags = $tagsById[$pkg['id']] ?? [];

                    $metadataProbability = $this->spamClassifier->predictSpamProbability($pkg['name'], $pkg['description'], $tags);
                    $safe = $this->spamClassifier->isConfidentlySafe($pkg['name'], $pkg['description'], $tags, $readmeHtml);
                    if (!$safe) {
                        $allSafe = false;
                    }

                    $readmeNote = '';
                    if ($readmeHtml !== null && $readmeHtml !== '') {
                        $readmeNote = sprintf(' readme_spam=%.3f', $this->spamClassifier->predictReadmeSpamProbability($readmeHtml));
                    }
                    $lines[] = sprintf('    [%s] %s (meta_spam=%.3f%s)', $safe ? 'safe' : 'KEEP', $pkg['name'], $metadataProbability, $readmeNote);
                }

                $decision = $allSafe ? '<info>CLEAR</info>' : 'keep';
                $output->writeln(sprintf('%s - %d suspect package(s) => %s', $vendor, \count($vendorPackages), $decision));
                foreach ($lines as $line) {
                    $output->writeln($line);
                }

                if ($allSafe) {
                    $clearedVendors++;
                    $clearedPackages += \count($vendorPackages);
                    if ($apply) {
                        $vendorRepo->verify($vendor);
                        $this->logger->info('Spam triage auto-cleared vendor', ['vendor' => $vendor, 'packages' => \count($vendorPackages)]);
                    }
                }
            }

            if ($apply && $clearedVendors > 0) {
                $this->statsd->increment('spam.triage.vendors_cleared', $clearedVendors);
            }

            $output->writeln('');
            $output->writeln(sprintf(
                '%s %d/%d package(s) across %d vendor(s); %d package(s) kept for review.',
                $apply ? 'Cleared' : 'Would clear',
                $clearedPackages,
                $totalPackages,
                $clearedVendors,
                $totalPackages - $clearedPackages,
            ));
        } finally {
            $this->locker->unlockCommand(__CLASS__);
        }

        return Command::SUCCESS;
    }

    /**
     * @param array<array{id: int, name: string, description: string|null, vendor: string}> $packages
     *
     * @return array<int, list<string>>
     */
    private function fetchTags(\App\Entity\PackageRepository $packageRepo, array $packages): array
    {
        $ids = array_map(static fn (array $pkg) => $pkg['id'], $packages);

        $tagsById = [];
        foreach (array_chunk($ids, 500) as $batch) {
            foreach ($packageRepo->getTagsByPackageIds($batch) as $id => $tags) {
                $tagsById[$id] = $tags;
            }
        }

        return $tagsById;
    }
}
