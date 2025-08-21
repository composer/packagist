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

namespace App\Entity;

use App\Service\UpdaterWorker;
use App\Util\HttpDownloaderOptionsFactory;
use App\Validator\PopularPackageSafety;
use App\Validator\TypoSquatters;
use App\Validator\Copyright;
use App\Validator\UniquePackage;
use App\Validator\ValidPackageRepository;
use App\Validator\VendorWritable;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Pcre\Preg;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Repository\VcsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Util\HttpDownloader;
use DateTimeImmutable;

enum PackageFreezeReason: string
{
    case Spam = 'spam';
    case RemoteIdMismatch = 'remote_id';
    case Gone = 'gone';

    public function translationKey(): string
    {
        return 'freezing_reasons.' . $this->value;
    }

    public function isSpam(): bool
    {
        return $this === self::Spam;
    }

    public function isGone(): bool
    {
        return $this === self::Gone;
    }

    public function isRemoteIdMismatch(): bool
    {
        return $this === self::RemoteIdMismatch;
    }
}

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @phpstan-import-type VersionArray from Version
 */
#[ORM\Entity(repositoryClass: 'App\Entity\PackageRepository')]
#[ORM\Table(name: 'package')]
#[ORM\UniqueConstraint(name: 'package_name_idx', columns: ['name'])]
#[ORM\Index(name: 'indexed_idx', columns: ['indexedAt'])]
#[ORM\Index(name: 'crawled_idx', columns: ['crawledAt'])]
#[ORM\Index(name: 'dumped_idx', columns: ['dumpedAt'])]
#[ORM\Index(name: 'dumped2_idx', columns: ['dumpedAtV2'])]
#[ORM\Index(name: 'repository_idx', columns: ['repository'])]
#[ORM\Index(name: 'remoteid_idx', columns: ['remoteId'])]
#[ORM\Index(name: 'dumped2_crawled_frozen_idx', columns: ['dumpedAtV2', 'crawledAt', 'frozen'])]
#[ORM\Index(name: 'vendor_idx', columns: ['vendor'])]
#[ORM\Index(name: 'frozen_idx', columns: ['frozen'])]
#[ORM\Index(name: 'type_frozen_idx', columns: ['type', 'frozen'])]
#[UniquePackage(groups: ['Create'])]
#[VendorWritable(groups: ['Create'])]
#[ValidPackageRepository(groups: ['Update', 'Default'])]
#[TypoSquatters(groups: ['Create'])]
#[Copyright(groups: ['Create'])]
#[PopularPackageSafety(groups: ['Update'])]
class Package
{
    public const AUTO_NONE = 0;
    public const AUTO_MANUAL_HOOK = 1;
    public const AUTO_GITHUB_HOOK = 2;

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private int $id;

    /**
     * Unique package name
     */
    #[ORM\Column(length: 191)]
    private string $name = '';

    #[ORM\Column(length: 191)]
    private string $vendor = '';

    #[ORM\Column(nullable: true)]
    private string|null $type = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private string|null $description = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string|null $language = null;

    #[ORM\Column(type: 'text', nullable: true)]
    private string|null $readme = null;

    #[ORM\Column(type: 'integer', nullable: true, name: 'github_stars')]
    private int|null $gitHubStars = null;

    #[ORM\Column(type: 'integer', nullable: true, name: 'github_watches')]
    private int|null $gitHubWatches = null;

    #[ORM\Column(type: 'integer', nullable: true, name: 'github_forks')]
    private int|null $gitHubForks = null;

    #[ORM\Column(type: 'integer', nullable: true, name: 'github_open_issues')]
    private int|null $gitHubOpenIssues = null;

    /**
     * @var Collection<int, Version>&Selectable<int, Version>
     */
    #[ORM\OneToMany(targetEntity: Version::class, mappedBy: 'package')]
    private Collection $versions;

    /**
     * @var Collection<int, User>&Selectable<int, User>
     */
    #[ORM\ManyToMany(targetEntity: User::class, inversedBy: 'packages')]
    #[ORM\JoinTable(name: 'maintainers_packages')]
    private Collection $maintainers;

    #[ORM\Column]
    #[Assert\NotBlank(groups: ['Update', 'Default'])]
    private string $repository;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private DateTimeImmutable|null $updatedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private DateTimeImmutable|null $crawledAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private DateTimeImmutable|null $indexedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private DateTimeImmutable|null $dumpedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private DateTimeImmutable|null $dumpedAtV2 = null;

    /**
     * @var Collection<int, Download>&Selectable<int, Download>
     */
    #[ORM\OneToMany(targetEntity: Download::class, mappedBy: 'package')]
    private Collection $downloads;

    #[ORM\Column(type: 'string', nullable: true)]
    private string|null $remoteId = null;

    /**
     * @var int one of self::AUTO_*
     */
    #[ORM\Column(type: 'smallint')]
    private int $autoUpdated = 0;

    #[ORM\Column(type: 'boolean')]
    private bool $abandoned = false;

    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private string|null $replacementPackage = null;

    #[ORM\Column(type: 'boolean', options: ['default' => false])]
    private bool $updateFailureNotified = false;

    /**
     * If set, the content is the reason for being marked suspicious
     */
    #[ORM\Column(type: 'string', length: 255, nullable: true)]
    private string|null $suspect = null;

    /**
     * If set, the content is the reason for being frozen
     */
    #[ORM\Column(nullable: true)]
    private PackageFreezeReason|null $frozen = null;

    /**
     * @internal
     * @var true|null|VcsDriverInterface
     */
    public VcsDriverInterface|bool|null $vcsDriver = true;
    /**
     * @internal
     */
    public string|null $vcsDriverError = null;

    /**
     * @var array<string, Version>|null lookup table for versions
     */
    private array|null $cachedVersions = null;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->maintainers = new ArrayCollection();
        $this->downloads = new ArrayCollection();
        $this->createdAt = new DateTimeImmutable();
    }

    /**
     * @param VersionRepository|array<string,VersionArray> $versionRepo Version repo or an already array-ified version dataset
     * @return array{
     *     name: string,
     *     description: string|null,
     *     time: truthy-string,
     *     maintainers: list<array{name: string, avatar_url: string}>,
     *     versions: array<string, VersionArray>,
     *     type: string|null,
     *     repository: string,
     *     github_stars: int|null,
     *     github_watchers: int|null,
     *     github_forks: int|null,
     *     github_open_issues: int|null,
     *     language: string|null,
     *     abandoned?: string|true,
     * }
     */
    public function toArray(VersionRepository|array $versionRepo, bool $serializeForApi = false): array
    {
        $maintainers = [];
        foreach ($this->getMaintainers() as $maintainer) {
            $maintainers[] = $maintainer->toArray();
        }

        if (is_array($versionRepo)) {
            $versions = $versionRepo;
        } else {
            $versions = [];
            $partialVersions = $this->getVersions()->toArray();
            while ($partialVersions) {
                $versionRepo->getEntityManager()->clear();

                $slice = array_splice($partialVersions, 0, 100);
                $fullVersions = $versionRepo->refreshVersions($slice);
                $versionData = $versionRepo->getVersionData(array_map(static function ($v) {
                    return $v->getId();
                }, $fullVersions));
                $versions = array_merge($versions, $versionRepo->detachToArray($fullVersions, $versionData, $serializeForApi));
            }
        }

        $data = [
            'name' => $this->getName(),
            'description' => $this->getDescription(),
            'time' => $this->getCreatedAt()->format('c'),
            'maintainers' => $maintainers,
            'versions' => $versions,
            'type' => $this->getType(),
            'repository' => $this->getRepository(),
            'github_stars' => $this->getGitHubStars(),
            'github_watchers' => $this->getGitHubWatches(),
            'github_forks' => $this->getGitHubForks(),
            'github_open_issues' => $this->getGitHubOpenIssues(),
            'language' => $this->getLanguage(),
        ];

        if ($this->isAbandoned()) {
            $data['abandoned'] = $this->getReplacementPackage() ?? true;
        }

        return $data;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
        $this->vendor = Preg::replace('{/.*$}', '', $this->name);
    }

    public function getName(): string
    {
        return $this->name;
    }

    /**
     * Get vendor prefix
     */
    public function getVendor(): string
    {
        return $this->vendor;
    }

    /**
     * @return array<string>|null Vendor and package name
     */
    public function getGitHubComponents(): array|null
    {
        if (Preg::isMatchStrictGroups('{^(?:git://|git@|https?://)github.com[:/]([^/]+)/(.+?)(?:\.git|/)?$}i', $this->getRepository(), $match)) {
            return $match;
        }

        return null;
    }

    /**
     * @return array<string>|null Vendor and package name
     */
    public function getGitLabComponents(): array|null
    {
        if (Preg::isMatchStrictGroups('{^(?:git://|git@|https?://)gitlab.com[:/]([^/]+)/(.+?)(?:\.git|/)?$}i', $this->getRepository(), $match)) {
            return $match;
        }

        return null;
    }

    public function isGitHub(): bool
    {
        return (bool) $this->getGitHubComponents();
    }

    public function isGitLab(): bool
    {
        return (bool) $this->getGitLabComponents();
    }

    /**
     * Get package name without vendor
     */
    public function getPackageName(): string
    {
        return Preg::replace('{^[^/]*/}', '', $this->name);
    }

    public function setDescription(string|null $description): void
    {
        $this->description = $description;
    }

    public function getDescription(): string|null
    {
        return $this->description;
    }

    public function setLanguage(string $language): void
    {
        $this->language = $language;
    }

    public function getLanguage(): string|null
    {
        return $this->language;
    }

    public function setReadme(string $readme): void
    {
        $this->readme = $readme;
    }

    public function getReadme(): string
    {
        return (string) $this->readme;
    }

    /**
     * Get readme with transformations that should not be done in the stored readme as they might not be valid in the long run
     */
    public function getOptimizedReadme(): string
    {
        if ($this->readme === null) {
            return '';
        }

        return str_replace(['<img src="https://raw.github.com/', '<img src="https://raw.githubusercontent.com/'], '<img src="https://rawcdn.githack.com/', $this->readme);
    }

    public function setGitHubStars(int|null $val): void
    {
        $this->gitHubStars = $val;
    }

    public function getGitHubStars(): int|null
    {
        return $this->gitHubStars;
    }

    public function getGitHubStarsUrl(): string|null
    {
        if ($this->isGitHub()) {
            return $this->getBrowsableRepository() . '/stargazers';
        }
        if ($this->isGitLab()) {
            return $this->getBrowsableRepository() . '/-/starrers';
        }

        return null;
    }

    public function setGitHubWatches(int|null $val): void
    {
        $this->gitHubWatches = $val;
    }

    public function getGitHubWatches(): int|null
    {
        return $this->gitHubWatches;
    }

    public function setGitHubForks(int|null $val): void
    {
        $this->gitHubForks = $val;
    }

    public function getGitHubForks(): int|null
    {
        return $this->gitHubForks;
    }

    public function getGitHubForksUrl(): string|null
    {
        if ($this->isGitHub()) {
            return $this->getBrowsableRepository() . '/forks';
        }
        if ($this->isGitLab()) {
            return $this->getBrowsableRepository() . '/-/forks';
        }

        return null;
    }

    public function setGitHubOpenIssues(int|null $val): void
    {
        $this->gitHubOpenIssues = $val;
    }

    public function getGitHubOpenIssues(): int|null
    {
        return $this->gitHubOpenIssues;
    }

    public function setCreatedAt(DateTimeImmutable $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function setRepository(string $repoUrl): void
    {
        $this->vcsDriver = null;

        // prevent local filesystem URLs
        if (Preg::isMatch('{^(\.|[a-z]:|/)}i', $repoUrl)) {
            return;
        }

        $repoUrl = Preg::replace('{^git@github.com:}i', 'https://github.com/', $repoUrl);
        $repoUrl = Preg::replace('{^git://github.com/}i', 'https://github.com/', $repoUrl);
        $repoUrl = Preg::replace('{^(https://github.com/.*?)\.git$}i', '$1', $repoUrl);
        $repoUrl = Preg::replace('{^(https://github.com/.*?)/$}i', '$1', $repoUrl);
        // support urls like https://github.com/foo/bar/tree/main/baz or other sub-URLs in a repo
        $repoUrl = Preg::replace('{^(https://github.com/[^/]+/[^/]++).+$}i', '$1', $repoUrl);

        $repoUrl = Preg::replace('{^git@gitlab.com:}i', 'https://gitlab.com/', $repoUrl);
        $repoUrl = Preg::replace('{^https?://(?:www\.)?gitlab\.com/(.*?)\.git$}i', 'https://gitlab.com/$1', $repoUrl);

        $repoUrl = Preg::replace('{^git@+bitbucket.org:}i', 'https://bitbucket.org/', $repoUrl);
        $repoUrl = Preg::replace('{^bitbucket.org:}i', 'https://bitbucket.org/', $repoUrl);
        $repoUrl = Preg::replace('{^https://[a-z0-9_-]*@bitbucket.org/}i', 'https://bitbucket.org/', $repoUrl);
        $repoUrl = Preg::replace('{^(https://bitbucket.org/[^/]+/[^/]+)/src/[^.]+}i', '$1.git', $repoUrl);

        // normalize protocol case
        $repoUrl = Preg::replaceCallbackStrictGroups('{^(https?|git|svn)://}i', static fn ($match) => strtolower($match[1]) . '://', $repoUrl);

        $this->repository = $repoUrl;
        $this->remoteId = null;

        // avoid user@host URLs
        if (Preg::isMatch('{https?://.+@}', $repoUrl)) {
            return;
        }

        // validate that this is a somewhat valid URL
        if (!Preg::isMatch('{^([a-z0-9][^@\s]+@[a-z0-9-_.]+:\S+ | [a-z0-9]+://\S+)$}Dx', $repoUrl)) {
            return;
        }

        // block env vars & ~ prefixes
        if (Preg::isMatch('{^[%$~]}', $repoUrl)) {
            return;
        }

        try {
            $io = new NullIO();
            $config = Factory::createConfig();
            $io->loadConfiguration($config);
            $httpDownloader = new HttpDownloader($io, $config, HttpDownloaderOptionsFactory::getOptions());
            $repository = new VcsRepository(['url' => $this->repository], $io, $config, $httpDownloader, null, null, UpdaterWorker::VCS_REPO_DRIVERS);

            $driver = $this->vcsDriver = $repository->getDriver();
            if (!$driver) {
                return;
            }
            $information = $driver->getComposerInformation($driver->getRootIdentifier());
            if (!isset($information['name']) || !is_string($information['name'])) {
                return;
            }
            if ('' === $this->name) {
                $this->setName(trim($information['name']));
            }
            if ($driver instanceof GitHubDriver) {
                $this->repository = $driver->getRepositoryUrl();
                if ($repoData = $driver->getRepoData()) {
                    $this->remoteId = parse_url($this->repository, PHP_URL_HOST).'/'.$repoData['id'];
                }
            }

            // when a package URL is updated to a new one we should assume it is now valid and not gone anymore
            if ($this->isFrozen() && $this->getFreezeReason() === PackageFreezeReason::Gone) {
                $this->unfreeze();
            }
        } catch (\Exception $e) {
            $this->vcsDriverError = '['.get_class($e).'] '.$e->getMessage();
        }
    }

    /**
     * Get repository
     *
     * @return string $repository
     */
    public function getRepository(): string
    {
        return $this->repository;
    }

    /**
     * Get a user-browsable version of the repository URL
     *
     * @return string $repository
     */
    public function getBrowsableRepository(): string
    {
        if (Preg::isMatch('{(://|@)bitbucket.org[:/]}i', $this->repository)) {
            return Preg::replace('{^(?:git@|https://|git://)bitbucket.org[:/](.+?)(?:\.git)?$}i', 'https://bitbucket.org/$1', $this->repository);
        }

        if (Preg::isMatch('{(://|@)github.com[:/]}i', $this->repository)) {
            return Preg::replace('{^(git://github.com/|git@github.com:)}', 'https://github.com/', $this->repository);
        }

        if (Preg::isMatch('{(://|@)gitlab.com[:/]}i', $this->repository)) {
            return Preg::replace('{^(git://gitlab.com/|git@gitlab.com:)}', 'https://gitlab.com/', $this->repository);
        }

        return $this->repository;
    }

    public function addVersion(Version $version): void
    {
        $this->versions[] = $version;
    }

    /**
     * @return Collection<int, Version>&Selectable<int, Version>
     */
    public function getVersions(): Collection
    {
        return $this->versions;
    }

    public function getVersion(string $normalizedVersion): Version|null
    {
        if (null === $this->cachedVersions) {
            $this->cachedVersions = [];
            foreach ($this->getVersions() as $version) {
                $this->cachedVersions[strtolower($version->getNormalizedVersion())] = $version;
            }
        }

        if (isset($this->cachedVersions[strtolower($normalizedVersion)])) {
            return $this->cachedVersions[strtolower($normalizedVersion)];
        }

        return null;
    }

    public function setUpdatedAt(DateTimeImmutable $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
        $this->setUpdateFailureNotified(false);
    }

    public function getUpdatedAt(): ?DateTimeImmutable
    {
        return $this->updatedAt;
    }

    public function wasUpdatedInTheLast24Hours(): bool
    {
        return $this->updatedAt && $this->updatedAt > new DateTimeImmutable('-24 hours');
    }

    public function setCrawledAt(?DateTimeImmutable $crawledAt): void
    {
        $this->crawledAt = $crawledAt;
    }

    public function getCrawledAt(): ?DateTimeImmutable
    {
        return $this->crawledAt;
    }

    public function setIndexedAt(?DateTimeImmutable $indexedAt): void
    {
        $this->indexedAt = $indexedAt;
    }

    public function getIndexedAt(): ?DateTimeImmutable
    {
        return $this->indexedAt;
    }

    public function setDumpedAt(?DateTimeImmutable $dumpedAt): void
    {
        $this->dumpedAt = $dumpedAt;
    }

    public function getDumpedAt(): ?DateTimeImmutable
    {
        return $this->dumpedAt;
    }

    public function setDumpedAtV2(?DateTimeImmutable $dumpedAt): void
    {
        $this->dumpedAtV2 = $dumpedAt;
    }

    public function getDumpedAtV2(): ?DateTimeImmutable
    {
        return $this->dumpedAtV2;
    }

    public function addMaintainer(User $maintainer): void
    {
        $this->maintainers[] = $maintainer;
    }

    /**
     * @return Collection<int, User>&Selectable<int, User>
     */
    public function getMaintainers(): Collection
    {
        return $this->maintainers;
    }

    public function isMaintainer(?User $user): bool
    {
        if (null === $user) {
            return false;
        }

        return $this->maintainers->contains($user);
    }

    public function setType(string $type): void
    {
        $this->type = $type;
    }

    public function getType(): string|null
    {
        return $this->type;
    }

    public function getInstallCommand(Version|null $version = null): string
    {
        if (in_array($this->getType(), ['php-ext', 'php-ext-zend'], true)) {
            return 'pie install '.$this->getName();
        }

        $command = 'create-project';

        if ('project' !== $this->getType()) {
            $command = 'require';

            if (null !== $version && $version->hasDevTag()) {
                $command .= ' --dev';
            }
        }

        return sprintf('composer %s %s', $command, $this->getName());
    }

    public function setRemoteId(string|null $remoteId): void
    {
        $this->remoteId = $remoteId;
    }

    public function getRemoteId(): string|null
    {
        return $this->remoteId;
    }

    /**
     * @param self::AUTO_* $autoUpdated
     */
    public function setAutoUpdated(int $autoUpdated): void
    {
        $this->autoUpdated = $autoUpdated;
    }

    /**
     * @return self::AUTO_*
     */
    public function getAutoUpdated(): int
    {
        assert(in_array($this->autoUpdated, [self::AUTO_NONE, self::AUTO_MANUAL_HOOK, self::AUTO_GITHUB_HOOK], true));

        return $this->autoUpdated;
    }

    /**
     * Get autoUpdated
     */
    public function isAutoUpdated(): bool
    {
        return $this->autoUpdated > 0;
    }

    /**
     * Set updateFailureNotified
     */
    public function setUpdateFailureNotified(bool $updateFailureNotified): void
    {
        $this->updateFailureNotified = $updateFailureNotified;
    }

    /**
     * Get updateFailureNotified
     */
    public function isUpdateFailureNotified(): bool
    {
        return $this->updateFailureNotified;
    }

    public function setSuspect(?string $reason): void
    {
        $this->suspect = $reason;
    }

    public function isSuspect(): bool
    {
        return !is_null($this->suspect);
    }

    public function getSuspect(): ?string
    {
        return $this->suspect;
    }

    public function freeze(PackageFreezeReason $reason): void
    {
        $this->frozen = $reason;
        // force re-indexing for spam packages to ensure they get deleted from the search index
        if ($reason === PackageFreezeReason::Spam) {
            $this->setIndexedAt(null);
        }
    }

    public function unfreeze(): void
    {
        if ($this->frozen === PackageFreezeReason::RemoteIdMismatch) {
            $this->setRemoteId(null);
        }
        $this->frozen = null;
        $this->setCrawledAt(null);
    }

    public function isFrozen(): bool
    {
        return !is_null($this->frozen);
    }

    public function getFreezeReason(): ?PackageFreezeReason
    {
        return $this->frozen;
    }

    public function isAbandoned(): bool
    {
        return $this->abandoned;
    }

    public function setAbandoned(bool $abandoned): void
    {
        $this->abandoned = $abandoned;
    }

    public function getReplacementPackage(): ?string
    {
        return $this->replacementPackage;
    }

    public function setReplacementPackage(?string $replacementPackage): void
    {
        if ('' === $replacementPackage) {
            $this->replacementPackage = null;
        } else {
            $this->replacementPackage = $replacementPackage;
        }
    }

    public static function sortVersions(Version $a, Version $b): int
    {
        $aVersion = $a->getNormalizedVersion();
        $bVersion = $b->getNormalizedVersion();

        // use branch alias for sorting if one is provided
        if (isset($a->getExtra()['branch-alias'][$aVersion])) {
            $aVersion = Preg::replace('{(.x)?-dev$}', '.9999999-dev', $a->getExtra()['branch-alias'][$aVersion]);
        }
        if (isset($b->getExtra()['branch-alias'][$bVersion])) {
            $bVersion = Preg::replace('{(.x)?-dev$}', '.9999999-dev', $b->getExtra()['branch-alias'][$bVersion]);
        }

        $aVersion = Preg::replace('{^dev-.*}', '0.0.0-alpha', $aVersion);
        $bVersion = Preg::replace('{^dev-.*}', '0.0.0-alpha', $bVersion);

        // sort default branch first if it is non numeric
        if ($aVersion === '0.0.0-alpha' && $a->isDefaultBranch()) {
            return -1;
        }
        if ($bVersion === '0.0.0-alpha' && $b->isDefaultBranch()) {
            return 1;
        }

        // equal versions are sorted by date
        if ($aVersion === $bVersion) {
            // make sure sort is stable
            if ($a->getReleasedAt() == $b->getReleasedAt()) {
                return $a->getNormalizedVersion() <=> $b->getNormalizedVersion();
            }

            return $b->getReleasedAt() > $a->getReleasedAt() ? 1 : -1;
        }

        // the rest is sorted by version
        return version_compare($bVersion, $aVersion);
    }
}
