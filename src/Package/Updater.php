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

namespace App\Package;

use App\Entity\ConflictLink;
use App\Entity\Dependent;
use App\Entity\DevRequireLink;
use App\Entity\PackageFreezeReason;
use App\Entity\ProvideLink;
use App\Entity\ReplaceLink;
use App\Entity\RequireLink;
use App\HtmlSanitizer\ReadmeImageSanitizer;
use App\HtmlSanitizer\ReadmeLinkSanitizer;
use App\Util\HttpDownloaderOptionsFactory;
use cebe\markdown\GithubMarkdown;
use Composer\Package\AliasPackage;
use Composer\Pcre\Preg;
use Composer\Repository\VcsRepository;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Repository\Vcs\GitLabDriver;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Repository\InvalidRepositoryException;
use Composer\Util\ErrorHandler;
use Composer\Util\HttpDownloader;
use Composer\Config;
use Composer\IO\IOInterface;
use App\Entity\Package;
use App\Entity\Tag;
use App\Entity\Version;
use App\Entity\VersionRepository;
use App\Entity\SuggestLink;
use App\Model\ProviderManager;
use App\Model\VersionIdCache;
use DateTimeImmutable;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\Persistence\ManagerRegistry;
use Doctrine\DBAL\Connection;
use App\Service\VersionCache;
use Composer\Package\CompletePackageInterface;
use Symfony\Component\HtmlSanitizer\HtmlSanitizer;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerAction;
use Symfony\Component\HtmlSanitizer\HtmlSanitizerConfig;
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Webmozart\Assert\Assert;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Updater
{
    use \App\Util\DoctrineTrait;

    public const UPDATE_EQUAL_REFS = 1;
    public const DELETE_BEFORE = 2;
    public const FORCE_DUMP = 4;

    private const SUPPORTED_LINK_TYPES = [
        'require' => [
            'composer-getter' => 'getRequires',
            'getter' => 'getRequire',
            'setter' => 'addRequireLink',
            'entity' => RequireLink::class,
        ],
        'conflict' => [
            'composer-getter' => 'getConflicts',
            'getter' => 'getConflict',
            'setter' => 'addConflictLink',
            'entity' => ConflictLink::class,
        ],
        'provide' => [
            'composer-getter' => 'getProvides',
            'getter' => 'getProvide',
            'setter' => 'addProvideLink',
            'entity' => ProvideLink::class,
        ],
        'replace' => [
            'composer-getter' => 'getReplaces',
            'getter' => 'getReplace',
            'setter' => 'addReplaceLink',
            'entity' => ReplaceLink::class,
        ],
        'devRequire' => [
            'composer-getter' => 'getDevRequires',
            'getter' => 'getDevRequire',
            'setter' => 'addDevRequireLink',
            'entity' => DevRequireLink::class,
        ],
    ];

    public function __construct(
        private ManagerRegistry $doctrine,
        private ProviderManager $providerManager,
        private VersionIdCache $versionIdCache,
        private MailerInterface $mailer,
        private string $mailFromEmail,
        private UrlGeneratorInterface $urlGenerator,
    ) {
        ErrorHandler::register();
    }

    public function __destruct()
    {
        restore_error_handler();
    }

    /**
     * Update a project
     *
     * @param VcsRepository $repository the repository instance used to update from
     * @param int $flags a few of the constants of this class
     * @param ExistingVersionsForUpdate|null $existingVersions
     */
    public function update(IOInterface $io, Config $config, Package $package, VcsRepository $repository, int $flags = 0, ?array $existingVersions = null, ?VersionCache $versionCache = null): Package
    {
        $httpDownloader = new HttpDownloader($io, $config, HttpDownloaderOptionsFactory::getOptions());

        $deleteDate = new \DateTimeImmutable('-1day');

        $em = $this->getEM();
        $rootIdentifier = null;

        $driver = $repository->getDriver();
        if (!$driver) {
            throw new \RuntimeException('Driver could not be established for package '.$package->getName().' ('.$package->getRepository().')');
        }

        if ($package->isFrozen()) {
            return $package;
        }

        $remoteId = null;
        if ($driver instanceof GitHubDriver) {
            $repoData = $driver->getRepoData();
            if (isset($repoData['repository']['id'])) {
                $remoteId = 'github.com/'.$repoData['repository']['id'];
            }
        } elseif ($driver instanceof GitLabDriver) {
            $repoData = $driver->getRepoData();
            if (isset($repoData['id'])) {
                $remoteId = 'gitlab.com/'.$repoData['id'];
            }
        }

        if ($remoteId !== null) {
            if (!$package->getRemoteId()) {
                $package->setRemoteId($remoteId);
            }
            if ($package->getRemoteId() !== $remoteId) {
                $package->freeze(PackageFreezeReason::RemoteIdMismatch);
                $em->flush();
                $io->writeError('<error>Skipping update as the source repository has a remote id mismatch. Expected '.$package->getRemoteId().' but got ' . $remoteId.'.</error>');

                $message = (new Email())
                    ->subject($package->getName().' frozen due to remote id mismatch')
                    ->from(new Address($this->mailFromEmail))
                    ->to($this->mailFromEmail)
                    ->text('Check out '.$this->urlGenerator->generate('view_package', ['name' => $package->getName()], UrlGeneratorInterface::ABSOLUTE_URL).' was not repo-jacked.')
                ;
                $message->getHeaders()->addTextHeader('X-Auto-Response-Suppress', 'OOF, DR, RN, NRN, AutoReply');
                $this->mailer->send($message);

                return $package;
            }
        }

        $rootIdentifier = $driver->getRootIdentifier();

        // always update the master branch / root identifier, as in case a package gets archived
        // we want to mark it abandoned automatically, but there will not be a new commit to trigger
        // an update
        if ($rootIdentifier !== '' && $versionCache) {
            $versionCache->clearVersion($rootIdentifier);
        }
        // migrate old packages to the new metadata storage for v2
        if ($versionCache && ($package->getUpdatedAt() === null || $package->getUpdatedAt() < new \DateTime('2020-06-20 00:00:00'))) {
            $versionCache->clearVersion('master');
            $versionCache->clearVersion('default');
            $versionCache->clearVersion('trunk');
        }

        $versions = $repository->getPackages();
        usort($versions, static function ($a, $b) {
            $aVersion = $a->getVersion();
            $bVersion = $b->getVersion();
            if ($aVersion === '9999999-dev' || 'dev-' === substr($aVersion, 0, 4)) {
                $aVersion = 'dev';
            }
            if ($bVersion === '9999999-dev' || 'dev-' === substr($bVersion, 0, 4)) {
                $bVersion = 'dev';
            }
            $aIsDev = $aVersion === 'dev' || substr($aVersion, -4) === '-dev';
            $bIsDev = $bVersion === 'dev' || substr($bVersion, -4) === '-dev';

            // push dev versions to the end
            if ($aIsDev !== $bIsDev) {
                return $aIsDev ? 1 : -1;
            }

            // equal versions are sorted by date
            if ($aVersion === $bVersion) {
                return $a->getReleaseDate() > $b->getReleaseDate() ? 1 : -1;
            }

            // the rest is sorted by version
            return version_compare($aVersion, $bVersion);
        });

        $versionRepository = $this->doctrine->getRepository(Version::class);

        if ($flags & self::DELETE_BEFORE) {
            foreach ($package->getVersions() as $version) {
                $versionRepository->remove($version);
            }

            $em->flush();
            $em->refresh($package);
        }

        if (!$existingVersions) {
            $existingVersions = $versionRepository->getVersionMetadataForUpdate($package);
        }

        $processedVersions = [];
        $lastProcessed = null;
        $idsToMarkUpdated = [];

        /** @var int|null|false $dependentSuggesterSource Version id to use as dependent/suggester source */
        $dependentSuggesterSource = null;
        foreach ($versions as $version) {
            if ($version instanceof AliasPackage) {
                continue;
            }
            if (!$version instanceof CompletePackageInterface) {
                throw new \LogicException('Received a package instance of type '.get_class($version).', expected a CompletePackageInterface instance');
            }

            if (isset($processedVersions[strtolower($version->getVersion())])) {
                $io->write('Skipping version '.$version->getPrettyVersion().' (duplicate of '.$processedVersions[strtolower($version->getVersion())]->getPrettyVersion().')', true, IOInterface::VERBOSE);
                continue;
            }
            $processedVersions[strtolower($version->getVersion())] = $version;

            $result = $this->updateInformation($io, $versionRepository, $package, $existingVersions, $version, $flags, $rootIdentifier);
            $versionId = false;
            if ($result['updated']) {
                assert($result['object'] instanceof Version);
                $em->flush();

                // detach version once flushed to avoid gathering lots of data in memory
                $em->detach($result['object']);

                $this->versionIdCache->insertVersion($package, $result['object']);
                $versionId = $result['object']->getId();
            } else {
                $idsToMarkUpdated[] = $result['id'];
            }

            // use the first version which should be the highest stable version by default
            if (null === $dependentSuggesterSource) {
                $dependentSuggesterSource = $versionId;
            }
            // if default branch is present however we prefer that as the canonical source of dependent/suggester
            if ($version->isDefaultBranch()) {
                $dependentSuggesterSource = $versionId;
            }

            // mark the version processed so we can prune leftover ones
            unset($existingVersions[$result['version']]);
        }

        if ($dependentSuggesterSource) {
            $this->doctrine->getRepository(Dependent::class)->updateDependentSuggesters($package->getId(), $dependentSuggesterSource);
        }

        // make sure versions that are still present but did not update are not pruned
        $em->getConnection()->executeStatement(
            'UPDATE package_version SET updatedAt = :now, softDeletedAt = NULL WHERE id IN (:ids) AND softDeletedAt IS NOT NULL',
            ['now' => date('Y-m-d H:i:s'), 'ids' => $idsToMarkUpdated],
            ['ids' => ArrayParameterType::INTEGER]
        );

        // remove outdated versions
        foreach ($existingVersions as $version) {
            if (
                // soft-deleted versions are really purged after a day
                (!is_null($version['softDeletedAt']) && new \DateTime($version['softDeletedAt']) < $deleteDate)
                // remove v1 normalized versions of dev-master/trunk/default immediately as they have been recreated as dev-master/trunk/default in a non-normalized way
                || ($version['normalizedVersion'] === '9999999-dev')
            ) {
                $versionEntity = $versionRepository->find($version['id']);
                if (null !== $versionEntity) {
                    $versionRepository->remove($versionEntity);
                }
                continue;
            }

            // set it to be soft-deleted so next update that occurs after deleteDate (1day) if the
            // version is still missing it will be really removed
            $em->getConnection()->executeStatement(
                'UPDATE package_version SET softDeletedAt = :now WHERE id = :id',
                ['now' => date('Y-m-d H:i:s'), 'id' => $version['id']]
            );
        }

        if (null !== ($match = $package->getGitHubComponents())) {
            $this->updateGitHubInfo($httpDownloader, $package, $match[1], $match[2], $driver);
        } elseif (null !== ($match = $package->getGitLabComponents())) {
            $this->updateGitLabInfo($httpDownloader, $io, $package, $match[1], $match[2], $driver);
        } else {
            $this->updateReadme($io, $package, $driver);
        }

        $usingDetails = '';
        try {
            $gitDriverProperty = new \ReflectionProperty($driver, 'gitDriver');
            if (null !== $gitDriverProperty->getValue($driver)) {
                $usingDetails = ' (via GitDriver fallback instance)';
            }
        } catch (\Throwable $e) {}
        $io->writeError('Updated from '.$package->getRepository().' using ' . $driver::class . $usingDetails);

        // make sure the package exists in the package list if for some reason adding it on submit failed
        if (!$this->providerManager->packageExists($package->getName())) {
            $this->providerManager->insertPackage($package);
        }

        $package->setUpdatedAt(new \DateTimeImmutable());
        $package->setCrawledAt(new \DateTimeImmutable());

        if ($flags & self::FORCE_DUMP) {
            $package->setDumpedAt(null);
            $package->setDumpedAtV2(null);
        }

        $em->flush();
        if ($repository->hadInvalidBranches()) {
            throw new InvalidRepositoryException('Some branches contained invalid data and were discarded, it is advised to review the log and fix any issues present in branches');
        }

        return $package;
    }

    /**
     * Keys info:
     *
     *  - updated (whether the version was updated or needs to be marked as updated)
     *  - id (version id, can be null for newly created versions)
     *  - version (normalized version from the composer package)
     *  - object (Version instance if it was updated)
     *
     * @param ExistingVersionsForUpdate $existingVersions
     * @return array{updated: true, id: int|null, version: string, object: Version}|array{updated: false, id: int|null, version: string, object: null}
     */
    private function updateInformation(IOInterface $io, VersionRepository $versionRepo, Package $package, array $existingVersions, CompletePackageInterface $data, int $flags, string $rootIdentifier): array
    {
        $em = $this->getEM();
        $version = new Version();
        $versionId = null;

        $normVersion = $data->getVersion();

        $existingVersion = $existingVersions[strtolower($normVersion)] ?? null;
        if ($existingVersion) {
            $source = $existingVersion['source'];
            if (
                // update if the source reference has changed (re-tag or new commit on branch)
                ($source['reference'] ?? null) !== $data->getSourceReference()
                // or the source has some corrupted github private url
                || (isset($source['url']) && is_string($source['url']) && Preg::isMatch('{^git@github.com:.*?\.git$}', $source['url']))
                // or if the right flag is set
                || ($flags & self::UPDATE_EQUAL_REFS)
                // or if the package must be marked abandoned from composer.json
                || ($data->isAbandoned() && !$package->isAbandoned())
                // or if the version default branch state has changed
                || ($data->isDefaultBranch() !== $version->isDefaultBranch())
            ) {
                $version = $versionRepo->find($existingVersion['id']);
                if (null === $version) {
                    throw new \LogicException('At this point a version should always be found');
                }
                $versionId = $version->getId();
            } else {
                return ['updated' => false, 'id' => $existingVersion['id'], 'version' => strtolower($normVersion), 'object' => null];
            }
        }

        $version->setName($package->getName());
        $version->setVersion($data->getPrettyVersion());
        $version->setNormalizedVersion($normVersion);
        $version->setDevelopment($data->isDev());
        $version->setPhpExt($data->getPhpExt());

        $em->persist($version);

        $descr = $this->sanitize($data->getDescription());
        $version->setDescription($descr);
        $version->setIsDefaultBranch($data->isDefaultBranch());

        // update the package description only for the default branch
        if ($data->isDefaultBranch()) {
            $package->setDescription($descr);
            $package->setType($this->sanitize($data->getType()));
            if ($data->isAbandoned() && !$package->isAbandoned()) {
                $io->write('Marking package abandoned as per composer metadata from '.$version->getVersion());
                $package->setAbandoned(true);
                if ($data->getReplacementPackage()) {
                    $package->setReplacementPackage($data->getReplacementPackage());
                }
            }
        }

        $version->setHomepage($data->getHomepage());
        $version->setLicense($data->getLicense() ?: []);

        $version->setPackage($package);
        $version->setUpdatedAt(new \DateTimeImmutable());
        $version->setSoftDeletedAt(null);
        $version->setReleasedAt($data->getReleaseDate() === null ? null : DateTimeImmutable::createFromInterface($data->getReleaseDate()));

        if ($data->getSourceType()) {
            $source['type'] = $data->getSourceType();
            $source['url'] = $data->getSourceUrl();
            // force public URLs even if the package somehow got downgraded to a GitDriver
            if (is_string($source['url']) && Preg::isMatch('{^git@github.com:(?P<repo>.*?)\.git$}', $source['url'], $match)) {
                $source['url'] = 'https://github.com/'.$match['repo'];
            }
            $source['reference'] = $data->getSourceReference();
            $version->setSource($source);
        } else {
            $version->setSource(null);
        }

        if ($data->getDistType()) {
            $dist['type'] = $data->getDistType();
            $dist['url'] = $data->getDistUrl();
            $dist['reference'] = $data->getDistReference();
            $dist['shasum'] = $data->getDistSha1Checksum();
            $version->setDist($dist);
        } else {
            $version->setDist(null);
        }

        if ($data->getType()) {
            $type = $this->sanitize($data->getType());
            $version->setType($type);
            if (null === $package->getType()) {
                $package->setType($type);
            }
        }

        $version->setTargetDir($data->getTargetDir());
        $version->setAutoload($data->getAutoload());
        $version->setExtra($data->getExtra());
        $version->setBinaries($data->getBinaries());
        $version->setIncludePaths($data->getIncludePaths());
        $version->setSupport($data->getSupport());
        $version->setFunding($data->getFunding());

        if ($data->getKeywords()) {
            $keywords = [];
            foreach ($data->getKeywords() as $keyword) {
                $keywords[mb_strtolower($keyword, 'UTF-8')] = $keyword;
            }

            $existingTags = [];
            foreach ($version->getTags() as $tag) {
                $existingTags[mb_strtolower($tag->getName(), 'UTF-8')] = $tag;
            }

            foreach ($keywords as $tagKey => $keyword) {
                if (isset($existingTags[$tagKey])) {
                    unset($existingTags[$tagKey]);
                    continue;
                }

                $tag = Tag::getByName($em, $keyword, true);
                if (!$version->getTags()->contains($tag)) {
                    $version->addTag($tag);
                }
            }

            foreach ($existingTags as $tag) {
                $version->getTags()->removeElement($tag);
            }
        } elseif (count($version->getTags())) {
            $version->getTags()->clear();
        }

        $version->setAuthors([]);
        if ($data->getAuthors()) {
            $authors = [];
            foreach ($data->getAuthors() as $authorData) {
                $author = [];

                foreach (['email', 'name', 'homepage', 'role'] as $field) {
                    if (isset($authorData[$field])) {
                        $author[$field] = trim($authorData[$field]);
                        if ('' === $author[$field]) {
                            unset($author[$field]);
                        }
                    }
                }

                // skip authors with no information
                if (!isset($authorData['email']) && !isset($authorData['name'])) {
                    continue;
                }

                $authors[] = $author;
            }
            $version->setAuthors($authors);
        }

        // handle links
        foreach (self::SUPPORTED_LINK_TYPES as $opts) {
            $links = [];
            foreach ($data->{$opts['composer-getter']}() as $link) {
                $constraint = $link->getPrettyConstraint();
                if (false !== strpos($constraint, ',') && false !== strpos($constraint, '@')) {
                    $constraint = Preg::replaceCallbackStrictGroups('{([><]=?\s*[^@]+?)@([a-z]+)}i', static function ($matches) {
                        if ($matches[2] === 'stable') {
                            return $matches[1];
                        }

                        return $matches[1].'-'.$matches[2];
                    }, $constraint);
                }

                $links[$link->getTarget()] = $constraint;
            }

            foreach ($version->{$opts['getter']}() as $link) {
                // clear links that have changed/disappeared (for updates)
                if (!isset($links[$link->getPackageName()]) || $links[$link->getPackageName()] !== $link->getPackageVersion()) {
                    $version->{$opts['getter']}()->removeElement($link);
                    $em->remove($link);
                } else {
                    // clear those that are already set
                    unset($links[$link->getPackageName()]);
                }
            }

            foreach ($links as $linkPackageName => $linkPackageVersion) {
                $class = $opts['entity'];
                $link = new $class;
                $link->setPackageName((string) $linkPackageName);
                $link->setPackageVersion($linkPackageVersion);
                $version->{$opts['setter']}($link);
                $link->setVersion($version);
                $em->persist($link);
            }
        }

        // handle suggests
        if ($suggests = $data->getSuggests()) {
            foreach ($version->getSuggest() as $link) {
                // clear links that have changed/disappeared (for updates)
                if (!isset($suggests[$link->getPackageName()]) || $suggests[$link->getPackageName()] !== $link->getPackageVersion()) {
                    $version->getSuggest()->removeElement($link);
                    $em->remove($link);
                } else {
                    // clear those that are already set
                    unset($suggests[$link->getPackageName()]);
                }
            }

            foreach ($suggests as $linkPackageName => $linkPackageVersion) {
                $link = new SuggestLink;
                $link->setPackageName($linkPackageName);
                $link->setPackageVersion($linkPackageVersion);
                $version->addSuggestLink($link);
                $link->setVersion($version);
                $em->persist($link);
            }
        } elseif (count($version->getSuggest())) {
            // clear existing suggests if present
            foreach ($version->getSuggest() as $link) {
                $em->remove($link);
            }
            $version->getSuggest()->clear();
        }

        return ['updated' => true, 'id' => $versionId, 'version' => strtolower($normVersion), 'object' => $version];
    }

    /**
     * Update the readme for $package from $repository.
     */
    private function updateReadme(IOInterface $io, Package $package, VcsDriverInterface $driver): void
    {
        // GitHub readme & info handled separately in updateGitHubInfo, sweep the special attributes
        $package->setGitHubStars(null);
        $package->setGitHubWatches(null);
        $package->setGitHubForks(null);
        $package->setGitHubOpenIssues(null);

        try {
            $composerInfo = $driver->getComposerInformation($driver->getRootIdentifier());
            if (isset($composerInfo['readme']) && is_string($composerInfo['readme'])) {
                $readmeFile = $composerInfo['readme'];
            } else {
                $readmeFile = 'README.md';
            }

            $ext = substr($readmeFile, (int) strrpos($readmeFile, '.'));
            if ($ext === $readmeFile) {
                $ext = '.txt';
            }

            switch ($ext) {
                case '.txt':
                    $source = $driver->getFileContent($readmeFile, $driver->getRootIdentifier());
                    if (!empty($source)) {
                        $package->setReadme('<pre>' . htmlspecialchars($source) . '</pre>');
                    }
                    break;

                case '.md':
                    $source = $driver->getFileContent($readmeFile, $driver->getRootIdentifier());
                    if (!empty($source)) {
                        $parser = new GithubMarkdown();
                        $readme = $parser->parse($source);

                        if (!empty($readme)) {
                            if (Preg::isMatch('{^(?:git://|git@|https?://)(gitlab.com|bitbucket.org)[:/]([^/]+)/(.+?)(?:\.git|/)?$}i', $package->getRepository(), $match)) {
                                $package->setReadme($this->prepareReadme($readme, $match[1], $match[2], $match[3]));
                            } else {
                                $package->setReadme($this->prepareReadme($readme));
                            }
                        }
                    }
                    break;
            }
        } catch (\Exception $e) {
            // we ignore all errors for this minor function
            $io->write(
                'Can not update readme. Error: ' . $e->getMessage(),
                true,
                IOInterface::VERBOSE
            );
        }
    }

    private function updateGitHubInfo(HttpDownloader $httpDownloader, Package $package, string $owner, string $repo, VcsDriverInterface $driver): void
    {
        if (!$driver instanceof GitHubDriver) {
            return;
        }

        $baseApiUrl = 'https://api.github.com/repos/'.$owner.'/'.$repo;

        $repoData = $driver->getRepoData();

        try {
            $opts = ['http' => ['header' => ['Accept: application/vnd.github.html+json', 'X-GitHub-Api-Version: 2022-11-28']]];
            $readme = $httpDownloader->get($baseApiUrl.'/readme', $opts)->getBody();
        } catch (\Exception $e) {
            if (!$e instanceof \Composer\Downloader\TransportException || $e->getCode() !== 404) {
                return;
            }
            // 404s just mean no readme present so we proceed with the rest
        }

        if (!empty($readme)) {
            // The content of all readmes, regardless of file type,
            // is returned as HTML by GitHub API
            $package->setReadme($this->prepareReadme($readme, 'github.com', $owner, $repo));
        }

        if (!empty($repoData['language']) && is_string($repoData['language'])) {
            $package->setLanguage($repoData['language']);
        }
        if (isset($repoData['stargazers_count']) && is_numeric($repoData['stargazers_count'])) {
            $package->setGitHubStars((int) $repoData['stargazers_count']);
        }
        if (isset($repoData['subscribers_count']) && is_numeric($repoData['subscribers_count'])) {
            $package->setGitHubWatches((int) $repoData['subscribers_count']);
        }
        if (isset($repoData['network_count']) && is_numeric($repoData['network_count'])) {
            $package->setGitHubForks((int) $repoData['network_count']);
        }
        if (isset($repoData['open_issues_count']) && is_numeric($repoData['open_issues_count'])) {
            $package->setGitHubOpenIssues((int) $repoData['open_issues_count']);
        }
    }

    private function updateGitLabInfo(HttpDownloader $httpDownloader, IOInterface $io, Package $package, string $owner, string $repo, VcsDriverInterface $driver): void
    {
        // GitLab provides a generic URL for the original formatted README,
        // which requires further elaboration. Here we use the already existing
        // function to handle it, and back here to populate the other available
        // metadata
        $this->updateReadme($io, $package, $driver);

        if (!$driver instanceof GitLabDriver) {
            return;
        }

        $repoData = $driver->getRepoData();

        if (isset($repoData['star_count']) && is_numeric($repoData['star_count'])) {
            $package->setGitHubStars((int) $repoData['star_count']);
        }
        if (isset($repoData['forks_count']) && is_numeric($repoData['forks_count'])) {
            $package->setGitHubForks((int) $repoData['forks_count']);
        }
        if (isset($repoData['open_issues_count']) && is_numeric($repoData['open_issues_count'])) {
            $package->setGitHubOpenIssues((int) $repoData['open_issues_count']);
        }

        // GitLab does not include a "watch" feature
        $package->setGitHubWatches(null);
    }

    /**
     * Prepare the readme by stripping elements and attributes that are not supported .
     */
    private function prepareReadme(string $readme, ?string $host = null, ?string $owner = null, ?string $repo = null): string
    {
        // detect base path for github readme if file is located in a subfolder like docs/README.md
        $basePath = '';
        if ($host === 'github.com' && Preg::isMatchStrictGroups('{^<div id="readme" [^>]+?data-path="([^"]+)"}', $readme, $match) && false !== strpos($match[1], '/')) {
            $basePath = dirname($match[1]);
        }
        if ($basePath) {
            $basePath .= '/';
        }

        $elements = [
            'p',
            'br',
            'small',
            'strong', 'b',
            'em', 'i',
            'strike',
            'sub', 'sup',
            'ins', 'del',
            'ol', 'ul', 'li',
            'h1', 'h2', 'h3', 'h4', 'h5', 'h6',
            'dl', 'dd', 'dt',
            'pre', 'code', 'samp', 'kbd',
            'q', 'blockquote', 'abbr', 'cite',
            'table', 'thead', 'tbody', 'tr',
            'span',
            'summary',
        ];

        $config = (new HtmlSanitizerConfig());
        $config = $config->defaultAction(HtmlSanitizerAction::Block);

        foreach ($elements as $el) {
            $config = $config->allowElement($el);
        }

        $config = $config
            ->allowElement('img', ['src', 'title', 'alt', 'width', 'height'])
            ->allowElement('a', ['href', 'target', 'id'])
            ->allowElement('td', ['colspan', 'rowspan'])
            ->allowElement('th', ['colspan', 'rowspan'])
            ->allowElement('details', ['open'])
            ->allowAttribute('align', ['th', 'td', 'p', 'h1', 'h2', 'h3', 'h4', 'h5', 'h6'])
            ->allowAttribute('class', '*')
            ->allowLinkSchemes(['https', 'http', 'mailto'])
            ->forceAttribute('a', 'rel', 'nofollow noindex noopener external ugc')
            ->withAttributeSanitizer(new ReadmeLinkSanitizer($host, $owner.'/'.$repo, $basePath))
            ->withAttributeSanitizer(new ReadmeImageSanitizer($host, $owner.'/'.$repo, $basePath))
            ->allowRelativeLinks()
            ->allowRelativeMedias()
            ->withMaxInputLength(10_000_000);

        $sanitizer = new HtmlSanitizer($config);
        $readme = $sanitizer->sanitizeFor('body', $readme);

        // remove first page element if it's a <h1> or <h2>, because it's usually
        // the project name or the `README` string which we don't need
        $readme = Preg::replace('{^<(h[12])[^>]*>.*</(?1)>}', '', $readme);

        return str_replace("\r\n", "\n", $readme);
    }

    /**
     * @phpstan-param string|null $str
     * @phpstan-return ($str is string ? string : null)
     */
    private function sanitize(string|null $str): string|null
    {
        if (null === $str) {
            return null;
        }

        // remove escape chars
        $str = Preg::replace("{\x1B(?:\[.)?}u", '', $str);

        return Preg::replace("{[\x01-\x1A]}u", '', $str);
    }
}
