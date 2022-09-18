<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Command\MigrateDownloadCountsCommand;
use App\Entity\Package;
use App\Entity\Version;
use DateInterval;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ObjectManager;
use Predis\Client;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Creates fake download statistics for each package.
 */
class DownloadFixtures extends Fixture implements DependentFixtureInterface
{
    public function __construct(
        private Client $redis,
        private MigrateDownloadCountsCommand $migrateDownloadCountsCommand
    ) {
    }

    public function getDependencies(): array
    {
        return [
            PackageFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $input  = new ArrayInput([]);
        $output = new ConsoleOutput();

        $output->writeln('Generating downloads...');

        /** @var Package[] $packages */
        $packages = $manager->getRepository(Package::class)->findAll();

        $progressBar = new ProgressBar($output, count($packages));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% (%remaining% left) %message%');

        $progressBar->setMessage('');
        $progressBar->start();

        // Set the Redis keys that would normally be set by the DownloadManager, for the whole period.

        foreach ($packages as $package) {
            $progressBar->setMessage($package->getName());
            $progressBar->display();

            /** @var EntityManagerInterface $manager */
            $latestVersion = $this->getLatestPackageVersion($manager, $package);

            $this->populateDownloads($package, $latestVersion);

            $progressBar->advance();
        }

        $progressBar->finish();
        $output->writeln('');

        // Then migrate the Redis keys to the db

        $output->writeln('Migrating downloads to db... (this may take some time)');
        $this->migrateDownloadCountsCommand->run($input, $output);
    }

    /**
     * Returns the latest non-dev version of the given package.
     */
    private function getLatestPackageVersion(EntityManagerInterface $manager, Package $package): Version
    {
        return $manager->createQueryBuilder()
            ->select('v')
            ->from(Version::class, 'v')
            ->where('v.package = :package')
            ->setParameter('package', $package)
            ->andWhere('v.development = false')
            ->orderBy('v.normalizedVersion', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();
    }

    /**
     * Populates Redis with pseudo-random daily download stats starting at the package creation date, and ending today.
     * The algorithm attempts to mimic a typical download stats curve.
     * The Redis keys set mimic the keys set by the DownloadManager.
     */
    private function populateDownloads(Package $package, Version $version): void
    {
        $date = DateTimeImmutable::createFromInterface($package->getCreatedAt());
        $endDate = (new \DateTimeImmutable('now'));

        $downloads = 0;

        for ($i = 0; ; $i++) {
            $val = min($i, 300);
            $downloads += random_int(- $val * 9, $val * 10);

            if ($downloads < 0) {
                $downloads = 0;
            }

            $day = $date->format('Ymd');
            $month = $date->format('Ym');

            $keys = [
                'downloads',
                'downloads:' . $day,
                'downloads:' . $month,

                'dl:' . $package->getId(),
                'dl:' . $package->getId() . ':' . $day,
                'dl:' . $package->getId() . '-' . $version->getId() . ':' . $day
            ];

            foreach ($keys as $key) {
                $this->redis->incrby($key, $downloads);
            }

            $date = $date->add(new DateInterval('P1D'));

            if ($date->diff($endDate)->invert) {
                break;
            }
        }
    }
}
