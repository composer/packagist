<?php

declare(strict_types=1);

namespace App\Package;

use App\Entity\Package;
use App\Entity\PackageRepository;
use App\Entity\User;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use Doctrine\ORM\EntityManagerInterface;
use Generator;
use function Safe\fclose;
use function Safe\fopen;
use function Safe\fwrite;
use function Safe\realpath;
use function Safe\rename;
use function Safe\tempnam;

/**
 * Dumps all packages, together with basic information, as a big JSON file, to the web root.
 */
class PackageDumper
{
    private EntityManagerInterface $em;

    private DownloadManager $downloadManager;

    private FavoriteManager $favoriteManager;

    private string $webDir;

    public function __construct(
        EntityManagerInterface $em,
        DownloadManager $downloadManager,
        FavoriteManager $favoriteManager,
        string $webDir
    ) {
        $this->em = $em;
        $this->downloadManager = $downloadManager;
        $this->favoriteManager = $favoriteManager;
        $this->webDir = realpath($webDir);
    }

    public function dump(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'packages-full.json.');

        $fp = fopen($tmpFile, 'wb');
        fwrite($fp, '[');

        $comma = '';

        foreach ($this->generatePackageData() as $packageData) {
            fwrite($fp, $comma . json_encode($packageData, JSON_PRETTY_PRINT));
            $comma = ',';
        }

        fwrite($fp, ']');
        fclose($fp);

        rename($tmpFile, $this->webDir . '/packages-full.json');
    }

    private function generatePackageData(): Generator
    {
        $lastId = 0;

        /** @var PackageRepository $packageRepository */
        $packageRepository = $this->em->getRepository(Package::class);

        while (true) {
            $this->em->clear();
            $packages = $this->getPackageBatch($lastId);

            if (! $packages) {
                break;
            }

            foreach ($packages as $package) {
                $maintainers = array_map(
                    fn(User $user) => $user->toArray(),
                    $package->getMaintainers()->toArray()
                );

                $data = [
                    'name'               => $package->getName(),
                    'description'        => $package->getDescription(),
                    'time'               => $package->getCreatedAt()->format('c'),
                    'maintainers'        => $maintainers,
                    'type'               => $package->getType(),
                    'repository'         => $package->getRepository(),
                    'github_stars'       => $package->getGitHubStars(),
                    'github_watchers'    => $package->getGitHubWatches(),
                    'github_forks'       => $package->getGitHubForks(),
                    'github_open_issues' => $package->getGitHubOpenIssues(),
                    'language'           => $package->getLanguage(),
                ];

                $data['abandoned'] = $package->isAbandoned() ? ($package->getReplacementPackage() ?? true) : false;
                $data['dependents'] = $packageRepository->getDependantCount($package->getName());
                $data['suggesters'] = $packageRepository->getSuggestCount($package->getName());
                $data['downloads'] = $this->downloadManager->getDownloads($package);
                $data['favers'] = $this->favoriteManager->getFaverCount($package);

                yield $data;

                $lastId = $package->getId();
            }
        }
    }

    /**
     * Returns a batch of max. 1000 packages.
     *
     * @return Package[]
     */
    private function getPackageBatch(int $lastId): array
    {
        return $this->em->createQueryBuilder()
            ->select('p')
            ->from(Package::class, 'p')
            ->where('p.id > :lastId')
            ->setParameter('lastId', $lastId)
            ->orderBy('p.id', 'ASC')
            ->setMaxResults(1000)
            ->getQuery()
            ->getResult();
    }
}
