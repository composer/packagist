<?php

namespace App\DataFixtures;

use App\Entity\Download;
use App\Entity\Package;
use App\Entity\Version;
use DateInterval;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Predis\Client;

/**
 * Creates fake download statistics for each package.
 */
class DownloadFixtures extends Fixture implements DependentFixtureInterface
{
    private Client $redis;

    public function __construct(Client $redis)
    {
        $this->redis = $redis;
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

        foreach ($packages as $package) {
            $latestVersion = $this->getLatestPackageVersion($manager, $package);

            $dataPoints = $this->createDataPoints();

            $totalDownloads = array_sum($dataPoints);
            $this->redis->set('dl:' . $package->getId(), $totalDownloads);

            $packageDownload = $this->createDownload($package->getId(), Download::TYPE_PACKAGE, $package, $dataPoints);
            $manager->persist($packageDownload);

            $versionDownload = $this->createDownload($latestVersion->getId(), Download::TYPE_VERSION, $package, $dataPoints);
            $manager->persist($versionDownload);
        }

        $manager->flush();
    }

    /**
     * Returns the latest non-dev version of the given package.
     */
    private function getLatestPackageVersion(ObjectManager $manager, Package $package): Version
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
     * Creates a Download with the given parameters.
     * The Download is populated with 1 year worth of pseudo-random download stats.
     */
    private function createDownload(int $id, int $type, Package $package, array $dataPoints): Download
    {
        $download = new Download();

        $download->setId($id);
        $download->setType($type);
        $download->setPackage($package);
        $download->setLastUpdated(new \DateTimeImmutable('now'));
        $download->setData($dataPoints);

        return $download;
    }

    /**
     * Returns pseudo-random data points for a download, as an associative array of YYYYMMDD to download count.
     */
    private function createDataPoints(): array
    {
        $result = [];

        $now = new \DateTimeImmutable('now');

        $date = $now->sub(new DateInterval('P365D'));
        $counter = 0;

        for ($i = 0; $i < 365; $i++) {
            $counter += random_int(- $i * 25, $i * 100);

            if ($counter < 0) {
                $counter = 0;
            }

            $result[$date->format('Ymd')] = $counter;

            $date = $date->add(new DateInterval('P1D'));
        }

        return $result;
    }
}
