<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Command\CompileStatsCommand;
use App\Command\MigrateDownloadCountsCommand;
use App\Command\MigratePhpStatsCommand;
use App\Entity\Package;
use App\Entity\Version;
use Composer\Pcre\Preg;
use DateInterval;
use DateTimeImmutable;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
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
class DownloadFixtures extends Fixture implements FixtureGroupInterface
{
    public function __construct(
        private Client $redis,
        private MigrateDownloadCountsCommand $migrateDownloadCountsCommand,
        private readonly MigratePhpStatsCommand $migratePhpStatsCommand,
        private readonly CompileStatsCommand $compileStatsCommand,
    ) {
    }

    public static function getGroups(): array
    {
        return ['downloads'];
    }

    public function load(ObjectManager $manager): void
    {
        $input  = new ArrayInput([]);
        $output = new ConsoleOutput();

        $output->writeln('Generating downloads...');

        $pkgNames = ['composer/pcre', 'monolog/monolog', 'twig/twig'];
        $packages = $manager->getRepository(Package::class)->findBy(['name' => $pkgNames]);

        $versions = [];
        foreach ($packages as $index => $package) {
            if ($package->getName() === 'composer/pcre') {
                $versions[$index] = $package->getVersions();
            } else {
                assert($manager instanceof EntityManagerInterface);
                $latestVersion = $this->getLatestPackageVersion($manager, $package);
                $versions[$index][] = $latestVersion;
            }
        }

        if ($versions === []) {
            echo 'No packages found, make sure to run "bin/console doctrine:fixtures:load --group base" before the download fixtures' . PHP_EOL;
            return;
        }

        echo 'Creating download fixtures for packages: '.implode(', ', $pkgNames).PHP_EOL;

        $progressBar = new ProgressBar($output, array_sum(array_map('count', $versions)));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% (%remaining% left) %message%');

        $progressBar->setMessage('');
        $progressBar->start();

        // Set the Redis keys that would normally be set by the DownloadManager, for the whole period.
        foreach ($packages as $index => $package) {
            $progressBar->setMessage($package->getName());
            $progressBar->display();

            foreach ($versions[$index] as $version) {
                $this->populateDownloads($package, $version);
                $progressBar->advance();
            }
        }

        $progressBar->finish();
        $output->writeln('');

        $manager->clear();

        $this->populateGlobalStats();

        // Then migrate the Redis keys to the db
        $output->writeln('Migrating downloads to db... (this may take some time)');
        $this->migrateDownloadCountsCommand->run($input, $output);
        $this->migratePhpStatsCommand->run($input, $output);
        $this->compileStatsCommand->run($input, $output);
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
        $endDate = new \DateTimeImmutable('now');

        $downloads = 0;

        for ($i = 0; ; $i++) {
            $val = min($i, 300);
            $downloads += random_int(-$val * 9, $val * 10);

            if ($downloads < 0) {
                $downloads = 0;
            }

            $day = $date->format('Ymd');
            $month = $date->format('Ym');

            $phpMinorPlatform = random_int(7, 8).'.'.random_int(0, 4);
            $minorVersion = Preg::replace('{^(\d+\.\d+).*}', '$1', $version->getVersion());

            $keys = [
                'dl:'.$package->getId() => $downloads,
                'dl:'.$package->getId().':'.$day => $downloads,
                'dl:'.$package->getId().'-'.$version->getId().':'.$day => $downloads,
            ];
            for ($major = 7; $major <= 8; $major++) {
                for ($minor = 0; $minor <= 4; $minor++) {
                    $phpMinorDl = random_int(0, $downloads + $minor * $major * 2);
                    $keys['phpplatform:'.$phpMinorPlatform.':'.$day] = $phpMinorDl;
                    $keys['phpplatform:'.$phpMinorPlatform.':'.$month] = $phpMinorDl;
                    $keys['phpplatform:'.$package->getId().'-'.$minorVersion.':'.$phpMinorPlatform.':'.$day] = $phpMinorDl;
                }
            }

            $this->redis->mset($keys);

            $date = $date->add(new DateInterval('P1D'));

            if ($date->diff($endDate)->invert) {
                break;
            }
        }
    }

    /**
     * Populates Redis with pseudo-random daily download stats globally
     * The algorithm attempts to mimic a typical download stats curve.
     * The Redis keys set mimic the keys set by the DownloadManager.
     */
    private function populateGlobalStats(): void
    {
        $date = new \DateTimeImmutable('-2years 3months');
        $endDate = new \DateTimeImmutable('now');

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
                'downloads' => $downloads,
                'downloads:' . $day => $downloads,
                'downloads:' . $month => $downloads,
            ];
            $this->redis->mset($keys);

            for ($major = 7; $major <= 8; $major++) {
                for ($minor = 0; $minor <= 4; $minor++) {
                    $phpMinorPlatform = $major.'.'.$minor;
                    $this->redis->hset('php:'.$phpMinorPlatform.':days', $day, $downloads + random_int(0, $major * $minor * 5));
                    $this->redis->hset('php:'.$phpMinorPlatform.':months', $month, $downloads + random_int(0, $major * $minor * 5));
                }
            }

            $date = $date->add(new DateInterval('P1D'));

            if ($date->diff($endDate)->invert) {
                break;
            }
        }
    }
}
