<?php

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
use App\Validator\TypoSquatters;
use App\Validator\Copyright;
use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\IO\NullIO;
use Composer\Pcre\Preg;
use Composer\Repository\VcsRepository;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Security\Core\User\UserInterface;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Composer\Repository\Vcs\GitHubDriver;
use Composer\Util\HttpDownloader;
use DateTimeInterface;

/**
 * @ORM\Entity(repositoryClass="App\Entity\PackageRepository")
 * @ORM\Table(
 *     name="package",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="package_name_idx", columns={"name"})},
 *     indexes={
 *         @ORM\Index(name="indexed_idx",columns={"indexedAt"}),
 *         @ORM\Index(name="crawled_idx",columns={"crawledAt"}),
 *         @ORM\Index(name="dumped_idx",columns={"dumpedAt"}),
 *         @ORM\Index(name="dumped2_idx",columns={"dumpedAtV2"}),
 *         @ORM\Index(name="repository_idx",columns={"repository"}),
 *         @ORM\Index(name="remoteid_idx",columns={"remoteId"}),
 *         @ORM\Index(name="dumped2_crawled_idx",columns={"dumpedAtV2","crawledAt"})
 *     }
 * )
 * @Assert\Callback(callback="isPackageUnique", groups={"Create"})
 * @Assert\Callback(callback="isVendorWritable", groups={"Create"})
 * @Assert\Callback(callback="isRepositoryValid", groups={"Update", "Default"})
 * @TypoSquatters(groups={"Create"})
 * @Copyright(groups={"Create"})
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Package
{
    const AUTO_NONE = 0;
    const AUTO_MANUAL_HOOK = 1;
    const AUTO_GITHUB_HOOK = 2;

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private int $id;

    /**
     * Unique package name
     *
     * @ORM\Column(length=191)
     */
    private string $name = '';

    /**
     * @ORM\Column(nullable=true)
     */
    private string|null $type = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string|null $description = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private string|null $language = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string|null $readme = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_stars")
     */
    private int|null $gitHubStars = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_watches")
     */
    private int|null $gitHubWatches = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_forks")
     */
    private int|null $gitHubForks = null;

    /**
     * @ORM\Column(type="integer", nullable=true, name="github_open_issues")
     */
    private int|null $gitHubOpenIssues = null;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Version", mappedBy="package")
     * @var Collection<int, Version>&Selectable<int, Version>
     */
    private Collection $versions;

    /**
     * @ORM\ManyToMany(targetEntity="User", inversedBy="packages")
     * @ORM\JoinTable(name="maintainers_packages")
     * @var Collection<int, User>&Selectable<int, User>
     */
    private Collection $maintainers;

    /**
     * @ORM\Column()
     * @Assert\NotBlank(groups={"Update", "Default"})
     */
    private string $repository;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTimeInterface $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private DateTimeInterface|null $updatedAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private DateTimeInterface|null $crawledAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private DateTimeInterface|null $indexedAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private DateTimeInterface|null $dumpedAt = null;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private DateTimeInterface|null $dumpedAtV2 = null;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\Download", mappedBy="package")
     * @var Collection<int, Download>&Selectable<int, Download>
     */
    private Collection $downloads;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private string|null $remoteId = null;

    /**
     * @ORM\Column(type="smallint")
     * @var int one of self::AUTO_*
     */
    private int $autoUpdated = 0;

    /**
     * @var bool
     * @ORM\Column(type="boolean")
     */
    private bool $abandoned = false;

    /**
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private string|null $replacementPackage = null;

    /**
     * @ORM\Column(type="boolean", options={"default"=false})
     */
    private bool $updateFailureNotified = false;

    /**
     * If set, the content is the reason for being marked suspicious
     *
     * @ORM\Column(type="string", length=255, nullable=true)
     */
    private string|null $suspect = null;

    private $entityRepository;
    private $router;

    /**
     * @var true|null|\Composer\Repository\Vcs\VcsDriverInterface
     */
    private $vcsDriver = true;
    private $vcsDriverError;

    /**
     * @var array lookup table for versions
     */
    private $cachedVersions;

    public function __construct()
    {
        $this->versions = new ArrayCollection();
        $this->maintainers = new ArrayCollection();
        $this->downloads = new ArrayCollection();
        $this->createdAt = new \DateTime;
    }

    public function toArray(VersionRepository $versionRepo, bool $serializeForApi = false): array
    {
        $maintainers = [];
        foreach ($this->getMaintainers() as $maintainer) {
            $maintainers[] = $maintainer->toArray();
        }

        $versions = [];
        $partialVersions = $this->getVersions()->toArray();
        while ($partialVersions) {
            $versionRepo->getEntityManager()->clear();

            $slice = array_splice($partialVersions, 0, 100);
            $fullVersions = $versionRepo->refreshVersions($slice);
            $versionData = $versionRepo->getVersionData(array_map(function ($v) { return $v->getId(); }, $fullVersions));
            $versions = array_merge($versions, $versionRepo->detachToArray($fullVersions, $versionData, $serializeForApi));
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
            $data['abandoned'] = $this->getReplacementPackage() ?: true;
        }

        return $data;
    }

    public function isRepositoryValid(ExecutionContextInterface $context): void
    {
        // vcs driver was not nulled which means the repository was not set/modified and is still valid
        if (true === $this->vcsDriver && '' !== $this->name) {
            return;
        }

        $property = 'repository';
        $driver = $this->vcsDriver;
        if (!is_object($driver)) {
            if (Preg::isMatch('{^http://}', $this->repository)) {
                $context->buildViolation('Non-secure HTTP URLs are not supported, make sure you use an HTTPS or SSH URL')
                    ->atPath($property)
                    ->addViolation()
                ;
            } elseif (Preg::isMatch('{https?://.+@}', $this->repository)) {
                $context->buildViolation('URLs with user@host are not supported, use a read-only public URL')
                    ->atPath($property)
                    ->addViolation()
                ;
            } elseif (is_string($this->vcsDriverError)) {
                $context->buildViolation('Uncaught Exception: '.htmlentities($this->vcsDriverError, ENT_COMPAT, 'utf-8'))
                    ->atPath($property)
                    ->addViolation()
                ;
            } else {
                $context->buildViolation('No valid/supported repository was found at the given URL')
                    ->atPath($property)
                    ->addViolation()
                ;
            }
            return;
        }
        try {
            $information = $driver->getComposerInformation($driver->getRootIdentifier());
            if (empty($information['name']) || !is_string($information['name'])) {
                $context->buildViolation('The package name was not found in the composer.json, make sure there is a name present.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (!Preg::isMatch('{^[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*$}iD', $information['name'])) {
                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match "[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*".')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (
                Preg::isMatch('{(free.*watch|watch.*free|(stream|online).*anschauver.*pelicula|ver.*completa|pelicula.*complet|season.*episode.*online|film.*(complet|entier)|(voir|regarder|guarda|assistir).*(film|complet)|full.*movie|online.*(free|tv|full.*hd)|(free|full|gratuit).*stream|movie.*free|free.*(movie|hack)|watch.*movie|watch.*full|generate.*resource|generate.*unlimited|hack.*coin|coin.*(hack|generat)|vbucks|hack.*cheat|hack.*generat|generat.*hack|hack.*unlimited|cheat.*(unlimited|generat)|(mod|cheat|apk).*(hack|cheat|mod)|hack.*(apk|mod|free|gold|gems|diamonds|coin)|putlocker|generat.*free|coins.*generat|(download|telecharg).*album|album.*(download|telecharg)|album.*(free|gratuit)|generat.*coins|unlimited.*coins|(fortnite|pubg|apex.*legend|t[1i]k.*t[o0]k).*(free|gratuit|generat|unlimited|coins|mobile|hack|follow))}i', str_replace(['.', '-'], '', $information['name']))
                && !Preg::isMatch('{^(hexmode|calgamo|liberty_code(_module)?|dvi|thelia|clayfreeman|watchfulli|assaneonline|awema-pl|magemodules?|simplepleb|modullo|modernmt|modina|havefnubb|lucid-modules|cointavia|magento-hackathon|pragmatic-modules|pmpr|moderntribe|teamneusta)/}', $information['name'])
            ) {
                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is blocked, if you think this is a mistake please get in touch with us.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (
                Preg::isMatch('{^([^/]*(symfony)[^/]*)/}', $information['name'], $match)
                && !$this->entityRepository->isVendorTaken($match[1])
            ) {
                $context->buildViolation('The vendor name '.htmlentities($match[1], ENT_COMPAT, 'utf-8').' is blocked, if you think this is a mistake please get in touch with us.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            $reservedVendors = ['php'];
            $bits = explode('/', strtolower($information['name']));
            if (in_array($bits[0], $reservedVendors, true)) {
                $context->buildViolation('The vendor name '.htmlentities($bits[0], ENT_COMPAT, 'utf-8').' is reserved, please use another name or reach out to us if you have a legitimate use for it.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            $reservedNames = ['nul', 'con', 'prn', 'aux', 'com1', 'com2', 'com3', 'com4', 'com5', 'com6', 'com7', 'com8', 'com9', 'lpt1', 'lpt2', 'lpt3', 'lpt4', 'lpt5', 'lpt6', 'lpt7', 'lpt8', 'lpt9'];
            $bits = explode('/', strtolower($information['name']));
            if (in_array($bits[0], $reservedNames, true) || in_array($bits[1], $reservedNames, true)) {
                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is reserved, package and vendor names can not match any of: '.implode(', ', $reservedNames).'.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (Preg::isMatch('{\.json$}', $information['name'])) {
                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is invalid, package names can not end in .json, consider renaming it or perhaps using a -json suffix instead.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            if (Preg::isMatch('{[A-Z]}', $information['name'])) {
                $suggestName = Preg::replace('{(?:([a-z])([A-Z])|([A-Z])([A-Z][a-z]))}', '\\1\\3-\\2\\4', $information['name']);
                $suggestName = strtolower($suggestName);

                $context->buildViolation('The package name '.htmlentities($information['name'], ENT_COMPAT, 'utf-8').' is invalid, it should not contain uppercase characters. We suggest using '.$suggestName.' instead.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }
        } catch (\Exception $e) {
            if ($e instanceof TransportException && $e->getCode() === 404) {
                $context->buildViolation('No composer.json was found in the '.$driver->getRootIdentifier().' branch.')
                    ->atPath($property)
                    ->addViolation()
                ;
                return;
            }

            $context->buildViolation('We had problems parsing your composer.json file, the parser reports: '.htmlentities($e->getMessage(), ENT_COMPAT, 'utf-8'))
                ->atPath($property)
                ->addViolation()
            ;
            return;
        }

        if ('' === $this->name) {
            $context->buildViolation('An unexpected error has made our parser fail to find a package name in your repository, if you think this is incorrect please try again')
                ->atPath($property)
                ->addViolation()
            ;
        }
    }

    public function setEntityRepository(PackageRepository $repository): void
    {
        $this->entityRepository = $repository;
    }

    public function setRouter(UrlGeneratorInterface $router): void
    {
        $this->router = $router;
    }

    public function isPackageUnique(ExecutionContextInterface $context): void
    {
        try {
            if ($this->entityRepository->findOneByName($this->name)) {
                $context->buildViolation('A package with the name <a href="'.$this->router->generate('view_package', ['name' => $this->name]).'">'.$this->name.'</a> already exists.')
                    ->atPath('repository')
                    ->addViolation()
                ;
            }
        } catch (\Doctrine\ORM\NoResultException $e) {}
    }

    public function isVendorWritable(ExecutionContextInterface $context): void
    {
        try {
            $vendor = $this->getVendor();
            if ($vendor && $this->entityRepository->isVendorTaken($vendor, $this->maintainers->first())) {
                $context->buildViolation('The vendor name "'.$vendor.'" was already claimed by someone else on Packagist.org. '
                        . 'You may ask them to add your package and give you maintainership access. '
                        . 'If they add you as a maintainer on any package in that vendor namespace, '
                        . 'you will then be able to add new packages in that namespace. '
                        . 'The packages already in that vendor namespace can be found at '
                        . '<a href="'.$this->router->generate('view_vendor', ['vendor' => $vendor]).'">'.$vendor.'</a>.'
                        . 'If those packages belong to you but were submitted by someone else, you can <a href="mailto:contact@packagist.org">contact us</a> to resolve the issue.')
                    ->atPath('repository')
                    ->addViolation()
                ;
            }
        } catch (\Doctrine\ORM\NoResultException $e) {}
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        if ('' === $this->name) {
            throw new \LogicException('This should not be called on an invalid package object which was not initialized with a name yet');
        }

        return $this->name;
    }

    /**
     * Get vendor prefix
     */
    public function getVendor(): string
    {
        return Preg::replace('{/.*$}', '', $this->name);
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

    public function setGitHubOpenIssues(int|null $val): void
    {
        $this->gitHubOpenIssues = $val;
    }

    public function getGitHubOpenIssues(): int|null
    {
        return $this->gitHubOpenIssues;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): DateTimeInterface
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

        $repoUrl = Preg::replace('{^git@gitlab.com:}i', 'https://gitlab.com/', $repoUrl);
        $repoUrl = Preg::replace('{^(https://gitlab.com/.*?)\.git$}i', '$1', $repoUrl);

        $repoUrl = Preg::replace('{^git@+bitbucket.org:}i', 'https://bitbucket.org/', $repoUrl);
        $repoUrl = Preg::replace('{^bitbucket.org:}i', 'https://bitbucket.org/', $repoUrl);
        $repoUrl = Preg::replace('{^https://[a-z0-9_-]*@bitbucket.org/}i', 'https://bitbucket.org/', $repoUrl);
        $repoUrl = Preg::replace('{^(https://bitbucket.org/[^/]+/[^/]+)/src/[^.]+}i', '$1.git', $repoUrl);

        // normalize protocol case
        $repoUrl = Preg::replaceCallback('{^(https?|git|svn)://}i', fn ($match) => strtolower($match[1]) . '://', $repoUrl);

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

        try {
            $io = new NullIO();
            $config = Factory::createConfig();
            $io->loadConfiguration($config);
            $httpDownloader = new HttpDownloader($io, $config);
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

        return Preg::replace('{^(git://github.com/|git@github.com:)}', 'https://github.com/', $this->repository);
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

    public function setUpdatedAt(DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
        $this->setUpdateFailureNotified(false);
    }

    public function getUpdatedAt(): ?DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function wasUpdatedInTheLast24Hours(): bool
    {
        return $this->updatedAt && $this->updatedAt > new \DateTime('-24 hours');
    }

    public function setCrawledAt(?DateTimeInterface $crawledAt): void
    {
        $this->crawledAt = $crawledAt;
    }

    public function getCrawledAt(): ?DateTimeInterface
    {
        return $this->crawledAt;
    }

    public function setIndexedAt(?DateTimeInterface $indexedAt): void
    {
        $this->indexedAt = $indexedAt;
    }

    public function getIndexedAt(): ?DateTimeInterface
    {
        return $this->indexedAt;
    }

    public function setDumpedAt(?DateTimeInterface $dumpedAt): void
    {
        $this->dumpedAt = $dumpedAt;
    }

    public function getDumpedAt(): ?DateTimeInterface
    {
        return $this->dumpedAt;
    }

    public function setDumpedAtV2(?DateTimeInterface $dumpedAt): void
    {
        $this->dumpedAtV2 = $dumpedAt;
    }

    public function getDumpedAtV2(): ?DateTimeInterface
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
     *
     * @return Boolean
     */
    public function isAutoUpdated(): bool
    {
        return $this->autoUpdated > 0;
    }

    /**
     * Set updateFailureNotified
     *
     * @param Boolean $updateFailureNotified
     */
    public function setUpdateFailureNotified($updateFailureNotified): void
    {
        $this->updateFailureNotified = $updateFailureNotified;
    }

    /**
     * Get updateFailureNotified
     *
     * @return Boolean
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

    /**
     * @return boolean
     */
    public function isAbandoned(): bool
    {
        return $this->abandoned;
    }

    /**
     * @param boolean $abandoned
     */
    public function setAbandoned($abandoned): void
    {
        $this->abandoned = $abandoned;
    }

    public function getReplacementPackage(): ?string
    {
        return $this->replacementPackage;
    }

    public function setReplacementPackage(?string $replacementPackage): void
    {
        $this->replacementPackage = $replacementPackage;
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
