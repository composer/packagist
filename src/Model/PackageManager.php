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

namespace App\Model;

use Algolia\AlgoliaSearch\Exceptions\AlgoliaException;
use Algolia\AlgoliaSearch\SearchClient;
use App\Entity\AuditRecord;
use App\Entity\Dependent;
use App\Entity\Download;
use App\Entity\EmptyReferenceCache;
use App\Entity\Package;
use App\Entity\PhpStat;
use App\Entity\User;
use App\Entity\Version;
use App\Service\CdnClient;
use App\Service\GitHubUserMigrationWorker;
use Composer\Pcre\Preg;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Contracts\HttpClient\Exception\DecodingExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\HttpExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Twig\Environment;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackageManager
{
    public function __construct(
        private ManagerRegistry $doctrine,
        private MailerInterface $mailer,
        private Environment $twig,
        private LoggerInterface $logger,
        /** @var array{from: string, fromName: string} */
        private array $options,
        private ProviderManager $providerManager,
        private SearchClient $algoliaClient,
        private string $algoliaIndexName,
        private GitHubUserMigrationWorker $githubWorker,
        private string $metadataDir,
        private Client $redis,
        private VersionIdCache $versionIdCache,
        private readonly CdnClient $cdnClient,
    ) {
    }

    public function deletePackage(Package $package): void
    {
        $versionRepo = $this->doctrine->getRepository(Version::class);
        foreach ($package->getVersions() as $version) {
            $versionRepo->remove($version, false);
        }

        if ($package->getAutoUpdated() === Package::AUTO_GITHUB_HOOK) {
            foreach ($package->getMaintainers() as $maintainer) {
                $token = $maintainer->getGithubToken();
                try {
                    if ($token && $this->githubWorker->deleteWebHook($token, $package)) {
                        break;
                    }
                } catch (TransportExceptionInterface|DecodingExceptionInterface|HttpExceptionInterface $e) {
                    // ignore
                }
            }
        }

        $em = $this->doctrine->getManager();

        // delete the files from the CDN first so if anything bad happens below we do not leave stale files on the CDN storage
        $this->deletePackageCdnMetadata($package->getName());

        $downloadRepo = $this->doctrine->getRepository(Download::class);
        $downloadRepo->deletePackageDownloads($package);

        $phpStatsRepo = $this->doctrine->getRepository(PhpStat::class);
        $phpStatsRepo->deletePackageStats($package);

        $dependentRepo = $this->doctrine->getRepository(Dependent::class);
        $dependentRepo->deletePackageDependentSuggesters($package->getId());

        $emptyRefRepo = $this->doctrine->getRepository(EmptyReferenceCache::class);
        $emptyRef = $emptyRefRepo->findOneBy(['package' => $package]);
        if ($emptyRef) {
            $em->remove($emptyRef);
            $em->flush();
        }

        $this->providerManager->deletePackage($package);
        $this->versionIdCache->deletePackage($package);
        $packageId = $package->getId();
        $packageName = $package->getName();

        $em->remove($package);
        $em->flush();

        $this->deletePackageMetadata($packageName);

        // delete redis stats
        try {
            $this->redis->del('views:'.$packageId);
        } catch (\Predis\Connection\ConnectionException $e) {
        }

        // attempt search index cleanup
        $this->deletePackageSearchIndex($packageName);

        // delete the files again just in case they got dumped above while the deletion was ongoing
        usleep(500000);
        $this->deletePackageCdnMetadata($packageName);
    }

    public function deletePackageMetadata(string $packageName): void
    {
        $metadataV2 = $this->metadataDir.'/p2/'.strtolower($packageName).'.json';
        if (file_exists($metadataV2)) {
            @unlink($metadataV2);
        }
        if (file_exists($metadataV2.'.gz')) {
            @unlink($metadataV2.'.gz');
        }
        $metadataV2Dev = $this->metadataDir.'/p2/'.strtolower($packageName).'~dev.json';
        if (file_exists($metadataV2Dev)) {
            @unlink($metadataV2Dev);
        }
        if (file_exists($metadataV2Dev.'.gz')) {
            @unlink($metadataV2Dev.'.gz');
        }

        $this->redis->zadd('metadata-deletes', [strtolower($packageName) => round(microtime(true) * 10000)]);
    }

    public function deletePackageCdnMetadata(string $packageName): void
    {
        $this->cdnClient->deleteMetadata('p2/'.strtolower($packageName.'.json'));
        $this->cdnClient->deleteMetadata('p2/'.strtolower($packageName.'~dev.json'));
    }

    public function deletePackageSearchIndex(string $packageName): void
    {
        try {
            $indexName = $this->algoliaIndexName;
            $algolia = $this->algoliaClient;
            $index = $algolia->initIndex($indexName);
            $index->deleteObject($packageName);
        } catch (AlgoliaException $e) {
        }
    }

    public function notifyUpdateFailure(Package $package, \Exception $e, ?string $details = null): bool
    {
        if (!$package->isUpdateFailureNotified()) {
            $recipients = [];
            foreach ($package->getMaintainers() as $maintainer) {
                $mail = $maintainer->getEmail();
                if ($mail && $maintainer->isNotifiableForFailures()) {
                    $recipients[$mail] = new Address($mail, $maintainer->getUsername());
                }
            }

            if ($recipients) {
                $details = strip_tags($details ?? '');
                $details = Preg::replace('{(?<=\n|^)Found cached composer\.json .*\n}', '', $details);
                $details = Preg::replace('{(?<=\n|^)Reading composer\.json .*\n}', '', $details);
                $details = Preg::replace('{(?<=\n|^)Importing (tag|branch) .*\n}', '', $details);

                $body = $this->twig->render('email/update_failed.txt.twig', [
                    'package' => $package,
                    'exception' => $e::class,
                    'exceptionMessage' => $e->getMessage(),
                    'details' => $details,
                ]);

                $message = new Email()
                    ->subject($package->getName().' failed to update, invalid composer.json data')
                    ->from(new Address($this->options['from'], $this->options['fromName']))
                    ->to(...array_values($recipients))
                    ->text($body)
                ;

                $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

                try {
                    $this->mailer->send($message);
                } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
                    $this->logger->error('['.$e::class.'] '.$e->getMessage());

                    return false;
                }
            }

            $package->setUpdateFailureNotified(true);
        }

        // make sure the package crawl time is updated so we avoid retrying failing packages more often than working ones
        if (!$package->getCrawledAt() || $package->getCrawledAt() < new \DateTimeImmutable()) {
            $package->setCrawledAt(new \DateTimeImmutable());
        }
        $this->doctrine->getManager()->flush();

        return true;
    }

    public function notifyNewMaintainer(User $user, Package $package): bool
    {
        $body = $this->twig->render('email/maintainer_added.txt.twig', [
            'package_name' => $package->getName(),
        ]);

        $message = new Email()
            ->subject('You have been added to '.$package->getName().' as a maintainer')
            ->from(new Address($this->options['from'], $this->options['fromName']))
            ->to((string) $user->getEmail())
            ->text($body)
        ;

        $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');

        try {
            $this->mailer->send($message);
        } catch (\Symfony\Component\Mailer\Exception\TransportExceptionInterface $e) {
            $this->logger->error('['.$e::class.'] '.$e->getMessage());

            return false;
        }

        return true;
    }

    /**
     * @param array<User> $newMaintainers
     */
    public function transferPackage(Package $package, array $newMaintainers): bool
    {
        $oldMaintainers = $package->getMaintainers()->toArray();
        $normalizedOldMaintainers = array_values(array_map(fn (User $user) => $user->getId(), $oldMaintainers));
        sort($normalizedOldMaintainers, SORT_NUMERIC);

        $normalizedMaintainers = array_values(array_map(fn (User $user) => $user->getId(), $newMaintainers));
        sort($normalizedMaintainers, SORT_NUMERIC);

        if ($normalizedMaintainers === $normalizedOldMaintainers) {
            return false;
        }

        $package->getMaintainers()->clear();
        foreach ($newMaintainers as $maintainer) {
            $package->addMaintainer($maintainer);
        }

        $this->doctrine->getManager()->persist(AuditRecord::packageTransferred($package, null, $oldMaintainers, $newMaintainers));

        return true;
    }
}
