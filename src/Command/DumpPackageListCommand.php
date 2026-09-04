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

use App\Entity\PackageRepository;
use App\Model\ProviderManager;
use App\Package\PackageListCache;
use App\Service\Locker;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class DumpPackageListCommand extends Command
{
    public function __construct(
        private PackageListCache $listCache,
        private ProviderManager $providerManager,
        private PackageRepository $repo,
        private Locker $locker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:dump-package-list')
            ->setDefinition([
                new InputOption('force', null, InputOption::VALUE_NONE, 'Rebuild even if no package was added or removed since the last run'),
                new InputOption('rebuild-set', null, InputOption::VALUE_NONE, 'Also rebuild the set:packages Redis set from the DB, to reset any drift'),
            ])
            ->setDescription('Dumps the gzipped /packages/list.json body into Redis')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $lockName = $this->getName() ?? __CLASS__;
        if (!$this->locker->lockCommand($lockName)) {
            if ($output->isVerbose()) {
                $output->writeln('Aborting, another dump is running already');
            }

            return 0;
        }

        try {
            // read before querying: a change landing during the build leaves the version ahead of
            // what we store as built, so the next run picks it up
            $version = $this->listCache->getVersion();
            $names = null;

            if ($input->getOption('force') || !$this->listCache->exists() || $version !== $this->listCache->getBuiltVersion()) {
                $names = $this->repo->getPackageNames();
                $this->listCache->write($names, $version);

                if ($output->isVerbose()) {
                    $output->writeln('Dumped '.\count($names).' package names at version '.$version);
                }
            }

            if ($input->getOption('rebuild-set')) {
                $this->providerManager->rebuildPackageSet($names ?? $this->repo->getPackageNames());
            }
        } finally {
            $this->locker->unlockCommand($lockName);
        }

        return 0;
    }
}
