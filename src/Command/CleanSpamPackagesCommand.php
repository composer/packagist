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
use App\Entity\PackageFreezeReason;
use App\Entity\Version;
use App\Entity\VersionRepository;
use App\Model\PackageManager;
use App\Model\ProviderManager;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class CleanSpamPackagesCommand extends Command
{
    use \App\Util\DoctrineTrait;

    public function __construct(
        private ManagerRegistry $doctrine,
        private ProviderManager $providerManager,
        private PackageManager $packageManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:clean-spam-packages')
            ->setDescription('Cleans up versions/metadata files from spam packages')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $em = $this->getEM();

        /** @var VersionRepository $versionRepo */
        $versionRepo = $em->getRepository(Version::class);
        $packages = $em
            ->getRepository(Package::class)
            ->createQueryBuilder('p')
            ->where('p.frozen = :frozen')
            ->setParameter('frozen', PackageFreezeReason::Spam)
            ->getQuery()->getResult();

        foreach ($packages as $package) {
            if (!$this->providerManager->packageExists($package->getName())) {
                $output->write('S');
                continue;
            }
            $output->write('.');
            foreach ($package->getVersions() as $version) {
                $versionRepo->remove($version);
            }

            $this->providerManager->deletePackage($package);
            $this->packageManager->deletePackageMetadata($package->getName());
            $this->packageManager->deletePackageCdnMetadata($package->getName());
            $this->packageManager->deletePackageSearchIndex($package->getName());
        }

        $em->flush();

        return 0;
    }
}
