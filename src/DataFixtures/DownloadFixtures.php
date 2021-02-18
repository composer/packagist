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
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Creates fake download statistics for each package.
 */
class DownloadFixtures extends Fixture implements DependentFixtureInterface
{
    private Client $redis;

    private MigrateDownloadCountsCommand $migrateDownloadCountsCommand;

    public function __construct(Client $redis, MigrateDownloadCountsCommand $command)
    {
        $this->redis = $redis;
        $this->migrateDownloadCountsCommand = $command;
    }

    public function getDependencies()
    {
        return [
            PackageFixtures::class,
        ];
    }

    public function load(ObjectManager $manager)
    {
        /** @var Package[] $packages */
        $packages = $manager->getRepository(Package::class)->findAll();

        // Set the Redis keys that would normally be set by the DownloadManager, for the whole period.

        $redisKeys = [];

        foreach ($packages as $package) {
            /** @var EntityManagerInterface $manager */
            $latestVersion = $this->getLatestPackageVersion($manager, $package);

            $redisKeys = $this->populateDownloads($redisKeys, $package, $latestVersion);
        }

        $this->msetRedis($redisKeys);

        // Migrate the download counts to the database.

        $input  = new ArrayInput([]);
        $output = new ConsoleOutput();

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
            ->andWhere('v.normalizedVersion NOT LIKE :dev')
            ->setParameter('dev', 'dev-%')
            ->orderBy('v.normalizedVersion', 'DESC')
            ->setMaxResults(1)
            ->getQuery()
            ->getSingleResult();
    }

    /**
     * Creates pseudo-random daily download stats starting at the package creation date, and ending today.
     * The algorithm attempts to mimic a typical download stats curve.
     *
     * Takes the current Redis keys and returns the updated Redis keys.
     * The Redis keys set mimic the keys set by the DownloadManager.
     */
    private function populateDownloads(array $redisKeys, Package $package, Version $version): array
    {
        $date = DateTimeImmutable::createFromMutable($package->getCreatedAt());
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
                $redisKeys[$key] = ($redisKeys[$key] ?? 0) + $downloads;
            }

            $date = $date->add(new DateInterval('P1D'));

            if ($date->diff($endDate)->invert) {
                break;
            }
        }

        return $redisKeys;
    }

    /**
     * Performs a Redis MSET in batches.
     * We can't set all values at once, or the operation fails with "Error while writing bytes to the server".
     */
    private function msetRedis(array $dict): void
    {
        $batchSize = 100;

        for ($start = 0; ; $start += $batchSize) {
            $batch = array_slice($dict, $start, $batchSize, true);

            if (! $batch) {
                break;
            }

            $this->redis->mset($batch);
            echo '.';
        }
    }
}
