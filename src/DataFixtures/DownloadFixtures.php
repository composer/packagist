<?php

namespace App\DataFixtures;

use App\Entity\Download;
use App\Entity\Package;
use App\Entity\Version;
use DateInterval;
use DateTime;
use DateTimeImmutable;
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

            $dataPoints = $this->createDataPoints($package->getCreatedAt());

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
     * The data points start at the package creation date, and end yesterday.
     * The algorithm attempts to mimic a typical download stats curve.
     */
    private function createDataPoints(DateTime $createdAt): array
    {
        $result = [];

        $date = DateTimeImmutable::createFromMutable($createdAt);
        $endDate = (new \DateTimeImmutable('yesterday'));

        $counter = 0;

        for ($i = 0; ; $i++) {
            $i = min($i, 300);
            $counter += random_int(- $i * 9, $i * 10);

            if ($counter < 0) {
                $counter = 0;
            }

            $result[$date->format('Ymd')] = $counter;

            $date = $date->add(new DateInterval('P1D'));

            if ($date->diff($endDate)->invert) {
                break;
            }
        }

        return $result;
    }
}
