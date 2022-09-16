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

use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use DateTimeInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 * @phpstan-type VersionArray array{
 *     name: string,
 *     description: string,
 *     keywords: list<string>,
 *     homepage: string,
 *     version: non-empty-string,
 *     version_normalized: non-empty-string,
 *     license: list<string>,
 *     authors: array<array{email?: string, homepage?: string, name?: string, role?: string}>,
 *     source: array<mixed>,
 *     dist: array<mixed>,
 *     type: string|null,
 *     support?: array<mixed>,
 *     funding?: array<mixed>,
 *     time?: string,
 *     autoload?: array<mixed>,
 *     extra?: array<mixed>,
 *     target-dir?: string,
 *     include-path?: list<string>,
 *     bin?: list<string>,
 *     default-branch?: true,
 *     require?: array<string, string>,
 *     require-dev?: array<string, string>,
 *     suggest?: array<string, string>,
 *     conflict?: array<string, string>,
 *     provide?: array<string, string>,
 *     replace?: array<string, string>,
 *     abandoned?: string|true
 * }
 */
#[ORM\Entity(repositoryClass: 'App\Entity\VersionRepository')]
#[ORM\Table(name: 'package_version')]
#[ORM\UniqueConstraint(name: 'pkg_ver_idx', columns: ['package_id', 'normalizedVersion'])]
#[ORM\Index(name: 'release_idx', columns: ['releasedAt'])]
#[ORM\Index(name: 'is_devel_idx', columns: ['development'])]
class Version
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[ORM\Column]
    #[Assert\NotBlank]
    private string $name;

    #[ORM\Column(type: 'text', nullable: true)]
    private string|null $description = null;

    #[ORM\Column(nullable: true)]
    private string|null $type = null;

    #[ORM\Column(nullable: true)]
    private string|null $targetDir = null;

    /**
     * @var array<mixed>
     */
    #[ORM\Column(type: 'array')]
    private array $extra = [];

    /**
     * @var Collection<int, Tag>&Selectable<int, Tag>
     */
    #[ORM\ManyToMany(targetEntity: Tag::class, inversedBy: 'versions')]
    #[ORM\JoinTable(name: 'version_tag')]
    #[ORM\JoinColumn(name: 'version_id', referencedColumnName: 'id')]
    #[ORM\InverseJoinColumn(name: 'tag_id', referencedColumnName: 'id')]
    private Collection $tags;

    #[ORM\ManyToOne(targetEntity: Package::class, fetch: 'EAGER', inversedBy: 'versions')]
    #[Assert\Type(type: Package::class)]
    private Package|null $package;

    #[ORM\Column(nullable: true)]
    #[Assert\Url]
    private string|null $homepage = null;

    #[ORM\Column]
    #[Assert\NotBlank]
    private string $version;

    #[ORM\Column(length: 191)]
    #[Assert\NotBlank]
    private string $normalizedVersion;

    #[ORM\Column(type: 'boolean')]
    #[Assert\NotBlank]
    private bool $development;

    /**
     * @var array<string>
     */
    #[ORM\Column(type: 'json')]
    private array $license;

    /**
     * @var Collection<int, RequireLink>&Selectable<int, RequireLink>
     */
    #[ORM\OneToMany(targetEntity: 'App\Entity\RequireLink', mappedBy: 'version')]
    private Collection $require;

    /**
     * @var Collection<int, ReplaceLink>&Selectable<int, ReplaceLink>
     */
    #[ORM\OneToMany(targetEntity: 'App\Entity\ReplaceLink', mappedBy: 'version')]
    private Collection $replace;

    /**
     * @var Collection<int, ConflictLink>&Selectable<int, ConflictLink>
     */
    #[ORM\OneToMany(targetEntity: 'App\Entity\ConflictLink', mappedBy: 'version')]
    private Collection $conflict;

    /**
     * @var Collection<int, ProvideLink>&Selectable<int, ProvideLink>
     */
    #[ORM\OneToMany(targetEntity: 'App\Entity\ProvideLink', mappedBy: 'version')]
    private Collection $provide;

    /**
     * @var Collection<int, DevRequireLink>&Selectable<int, DevRequireLink>
     */
    #[ORM\OneToMany(targetEntity: 'App\Entity\DevRequireLink', mappedBy: 'version')]
    private Collection $devRequire;

    /**
     * @var Collection<int, SuggestLink>&Selectable<int, SuggestLink>
     */
    #[ORM\OneToMany(targetEntity: 'App\Entity\SuggestLink', mappedBy: 'version')]
    private Collection $suggest;

    /**
     * @var array{type: string|null, url: string|null, reference: string|null}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private array|null $source = null;

    /**
     * @var array{type: string|null, url: string|null, reference: string|null, shasum: string|null}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private array|null $dist = null;

    /**
     * @var array{psr-0?: array<string, string|string[]>, psr-4?: array<string, string|string[]>, classmap?: list<string>, files?: list<string>}
     */
    #[ORM\Column(type: 'json')]
    private array $autoload;

    /**
     * @var array<string>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private array|null $binaries = null;

    /**
     * @var array<string>
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private array|null $includePaths = null;

    /**
     * @var array{issues?: string, forum?: string, wiki?: string, source?: string, email?: string, irc?: string, docs?: string, rss?: string, chat?: string}|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private array|null $support = null;

    /**
     * @var array<array{type?: string, url?: string}>|null
     */
    #[ORM\Column(type: 'json', nullable: true)]
    private array|null $funding = null;

    /**
     * @var array<array{name?: string, homepage?: string, email?: string, role?: string}>
     */
    #[ORM\Column(name: 'authors', type: 'json')]
    private array $authors = [];

    #[ORM\Column(name: 'defaultBranch', type: 'boolean', options: ['default' => false])]
    private bool $isDefaultBranch = false;

    #[ORM\Column(type: 'datetime')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private DateTimeInterface|null $softDeletedAt = null;

    #[ORM\Column(type: 'datetime')]
    private DateTimeInterface $updatedAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private DateTimeInterface|null $releasedAt = null;

    public function __construct()
    {
        $this->tags = new ArrayCollection();
        $this->require = new ArrayCollection();
        $this->replace = new ArrayCollection();
        $this->conflict = new ArrayCollection();
        $this->provide = new ArrayCollection();
        $this->devRequire = new ArrayCollection();
        $this->suggest = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable;
        $this->updatedAt = new \DateTimeImmutable;
    }

    /**
     * @return VersionArray
     */
    public function toArray(array $versionData, bool $serializeForApi = false): array
    {
        if (isset($versionData[$this->id]['tags'])) {
            $tags = $versionData[$this->id]['tags'];
        } else {
            $tags = [];
            foreach ($this->getTags() as $tag) {
                $tags[] = $tag->getName();
            }
        }

        $authors = $this->getAuthors();
        foreach ($authors as &$author) {
            uksort($author, [$this, 'sortAuthorKeys']);
        }
        unset($author);

        $data = [
            'name' => $this->getName(),
            'description' => (string) $this->getDescription(),
            'keywords' => $tags,
            'homepage' => (string) $this->getHomepage(),
            'version' => $this->getVersion(),
            'version_normalized' => $this->getNormalizedVersion(),
            'license' => $this->getLicense(),
            'authors' => $authors,
            'source' => $this->getSource(),
            'dist' => $this->getDist(),
            'type' => $this->getType(),
        ];

        if ($serializeForApi && $this->getSupport()) {
            $data['support'] = $this->getSupport();
        }
        if ($this->getFunding()) {
            $data['funding'] = $this->getFundingSorted();
        }
        if ($this->getReleasedAt()) {
            $data['time'] = $this->getReleasedAt()->format('Y-m-d\TH:i:sP');
        }
        if ($this->getAutoload()) {
            $data['autoload'] = $this->getAutoload();
        }
        if ($this->getExtra()) {
            $data['extra'] = $this->getExtra();
        }
        if ($this->getTargetDir()) {
            $data['target-dir'] = $this->getTargetDir();
        }
        if ($this->getIncludePaths()) {
            $data['include-path'] = $this->getIncludePaths();
        }
        if ($this->getBinaries()) {
            $data['bin'] = $this->getBinaries();
        }

        $supportedLinkTypes = [
            'require' => 'require',
            'devRequire' => 'require-dev',
            'suggest' => 'suggest',
            'conflict' => 'conflict',
            'provide' => 'provide',
            'replace' => 'replace',
        ];

        if ($this->isDefaultBranch()) {
            $data['default-branch'] = true;
        }

        foreach ($supportedLinkTypes as $method => $linkType) {
            if (isset($versionData[$this->id][$method])) {
                foreach ($versionData[$this->id][$method] as $link) {
                    $data[$linkType][$link['name']] = $link['version'];
                }
                continue;
            }
            /** @var PackageLink $link */
            foreach ($this->{'get'.$method}() as $link) {
                $link = $link->toArray();
                $data[$linkType][key($link)] = current($link);
            }
        }

        if ($this->getPackage()->isAbandoned()) {
            $data['abandoned'] = $this->getPackage()->getReplacementPackage() ?: true;
        }

        return $data;
    }

    /**
     * @return VersionArray
     */
    public function toV2Array(array $versionData): array
    {
        $array = $this->toArray($versionData);

        if ($this->getSupport()) {
            $array['support'] = $this->getSupport();
            ksort($array['support']);
        }

        return $array;
    }

    public function equals(Version $version): bool
    {
        return strtolower($version->getName()) === strtolower($this->getName())
            && strtolower($version->getNormalizedVersion()) === strtolower($this->getNormalizedVersion());
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
        return $this->name;
    }

    /**
     * @return list<string>
     */
    public function getNames(?array $versionData = null): array
    {
        $names = [
            strtolower($this->name) => true,
        ];

        if (isset($versionData[$this->id])) {
            foreach (($versionData[$this->id]['replace'] ?? []) as $link) {
                $names[strtolower($link['name'])] = true;
            }

            foreach (($versionData[$this->id]['provide'] ?? []) as $link) {
                $names[strtolower($link['name'])] = true;
            }
        } else {
            foreach ($this->getReplace() as $link) {
                $names[strtolower($link->getPackageName())] = true;
            }

            foreach ($this->getProvide() as $link) {
                $names[strtolower($link->getPackageName())] = true;
            }
        }

        return array_keys($names);
    }

    public function setDescription(string|null $description): void
    {
        $this->description = $description;
    }

    public function getDescription(): string|null
    {
        return $this->description;
    }

    public function setHomepage(string|null $homepage): void
    {
        $this->homepage = $homepage;
    }

    public function getHomepage(): string|null
    {
        return $this->homepage;
    }

    public function setVersion(string $version): void
    {
        $this->version = $version;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getRequireVersion(): string
    {
        return Preg::replace('{^v(\d)}', '$1', str_replace('.x-dev', '.*@dev', $this->getVersion()));
    }

    public function setNormalizedVersion(string $normalizedVersion): void
    {
        $this->normalizedVersion = $normalizedVersion;
    }

    public function getNormalizedVersion(): string
    {
        return $this->normalizedVersion;
    }

    public function setLicense(array $license): void
    {
        $this->license = $license;
    }

    public function getLicense(): array
    {
        return $this->license;
    }

    public function setSource(array|null $source): void
    {
        $this->source = $source;
    }

    public function getSource(): array|null
    {
        return $this->source;
    }

    public function setDist(array|null $dist): void
    {
        $this->dist = $dist;
    }

    public function getDist(): array|null
    {
        return $this->dist;
    }

    public function setAutoload(array $autoload): void
    {
        $this->autoload = $autoload;
    }

    public function getAutoload(): array
    {
        return $this->autoload;
    }

    public function setBinaries(array|null $binaries): void
    {
        $this->binaries = $binaries;
    }

    public function getBinaries(): array|null
    {
        return $this->binaries;
    }

    public function setIncludePaths(array|null $paths): void
    {
        $this->includePaths = $paths;
    }

    public function getIncludePaths(): array|null
    {
        return $this->includePaths;
    }

    public function setSupport(array|null $support): void
    {
        $this->support = $support;
    }

    public function getSupport(): array|null
    {
        return $this->support;
    }

    /**
     * @param array<array{type?: string, url?: string}>|null $funding
     */
    public function setFunding(array|null $funding): void
    {
        $this->funding = $funding;
    }

    /**
     * @return array<array{type?: string, url?: string}>|null
     */
    public function getFunding(): ?array
    {
        return $this->funding;
    }

    /**
     * Get funding, sorted to help the V2 metadata compression algo
     * @return array<array{type?: string, url?: string}>|null
     */
    public function getFundingSorted(): ?array
    {
        if ($this->funding === null) {
            return null;
        }

        $funding = $this->funding;
        usort($funding, static function ($a, $b) {
            $keyA = ($a['type'] ?? '') . ($a['url'] ?? '');
            $keyB = ($b['type'] ?? '') . ($b['url'] ?? '');

            return $keyA <=> $keyB;
        });

        return $funding;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function setReleasedAt(DateTimeInterface|null $releasedAt): void
    {
        $this->releasedAt = $releasedAt;
    }

    public function getReleasedAt(): DateTimeInterface|null
    {
        return $this->releasedAt;
    }

    public function setPackage(Package $package): void
    {
        $this->package = $package;
    }

    public function getPackage(): Package
    {
        assert($this->package instanceof Package);

        return $this->package;
    }

    /**
     * Get tags
     *
     * @return Collection<int, Tag>&Selectable<int, Tag>
     */
    public function getTags(): Collection
    {
        return $this->tags;
    }

    public function setUpdatedAt(DateTimeInterface $updatedAt): void
    {
        $this->updatedAt = $updatedAt;
    }

    public function getUpdatedAt(): DateTimeInterface
    {
        return $this->updatedAt;
    }

    public function setSoftDeletedAt(DateTimeInterface|null $softDeletedAt): void
    {
        $this->softDeletedAt = $softDeletedAt;
    }

    public function getSoftDeletedAt(): DateTimeInterface|null
    {
        return $this->softDeletedAt;
    }

    /**
     * @return array<array{name?: string, homepage?: string, email?: string, role?: string}>
     */
    public function getAuthors(): array
    {
        return $this->authors;
    }

    /**
     * @param array<array{name?: string, homepage?: string, email?: string, role?: string}> $authors
     */
    public function setAuthors(array $authors): void
    {
        $this->authors = $authors;
    }

    public function isDefaultBranch(): bool
    {
        return $this->isDefaultBranch;
    }

    public function setIsDefaultBranch(bool $isDefaultBranch): void
    {
        $this->isDefaultBranch = $isDefaultBranch;
    }

    public function setType(string|null $type): void
    {
        $this->type = $type;
    }

    public function getType(): string|null
    {
        return $this->type;
    }

    public function setTargetDir(string|null $targetDir): void
    {
        $this->targetDir = $targetDir;
    }

    public function getTargetDir(): string|null
    {
        return $this->targetDir;
    }

    public function setExtra(array $extra): void
    {
        $this->extra = $extra;
    }

    public function getExtra(): array
    {
        return $this->extra;
    }

    public function setDevelopment(bool $development): void
    {
        $this->development = $development;
    }

    public function getDevelopment(): bool
    {
        return $this->development;
    }

    public function isDevelopment(): bool
    {
        return $this->getDevelopment();
    }

    public function addTag(Tag $tag): void
    {
        $this->tags[] = $tag;
    }

    public function addRequireLink(RequireLink $require): void
    {
        $this->require[] = $require;
    }

    /**
     * Get require
     *
     * @return Collection<int, RequireLink>&Selectable<int, RequireLink>
     */
    public function getRequire(): Collection
    {
        return $this->require;
    }

    public function addReplaceLink(ReplaceLink $replace): void
    {
        $this->replace[] = $replace;
    }

    /**
     * Get replace
     *
     * @return Collection<int, ReplaceLink>&Selectable<int, ReplaceLink>
     */
    public function getReplace(): Collection
    {
        return $this->replace;
    }

    public function addConflictLink(ConflictLink $conflict): void
    {
        $this->conflict[] = $conflict;
    }

    /**
     * Get conflict
     *
     * @return Collection<int, ConflictLink>&Selectable<int, ConflictLink>
     */
    public function getConflict(): Collection
    {
        return $this->conflict;
    }

    public function addProvideLink(ProvideLink $provide): void
    {
        $this->provide[] = $provide;
    }

    /**
     * Get provide
     *
     * @return Collection<int, ProvideLink>&Selectable<int, ProvideLink>
     */
    public function getProvide(): Collection
    {
        return $this->provide;
    }

    public function addDevRequireLink(DevRequireLink $devRequire): void
    {
        $this->devRequire[] = $devRequire;
    }

    /**
     * Get devRequire
     *
     * @return Collection<int, DevRequireLink>&Selectable<int, DevRequireLink>
     */
    public function getDevRequire(): Collection
    {
        return $this->devRequire;
    }

    public function addSuggestLink(SuggestLink $suggest): void
    {
        $this->suggest[] = $suggest;
    }

    /**
     * Get suggest
     *
     * @return Collection<int, SuggestLink>&Selectable<int, SuggestLink>
     */
    public function getSuggest(): Collection
    {
        return $this->suggest;
    }

    public function hasVersionAlias(): bool
    {
        return $this->getDevelopment() && $this->getVersionAlias();
    }

    public function getVersionAlias(): string
    {
        $extra = $this->getExtra();

        if (isset($extra['branch-alias'][$this->getVersion()])) {
            $parser = new VersionParser;
            $version = $parser->normalizeBranch(str_replace('-dev', '', $extra['branch-alias'][$this->getVersion()]));

            return Preg::replace('{(\.9{7})+}', '.x', $version);
        }

        return '';
    }

    public function getRequireVersionAlias(): string
    {
        return str_replace('.x-dev', '.*@dev', $this->getVersionAlias());
    }

    public function __toString(): string
    {
        return $this->name.' '.$this->version.' ('.$this->normalizedVersion.')';
    }

    private function sortAuthorKeys(string $a, string $b): int
    {
        static $order = ['name' => 1, 'email' => 2, 'homepage' => 3, 'role' => 4];
        $aIndex = $order[$a] ?? 5;
        $bIndex = $order[$b] ?? 5;
        if ($aIndex === $bIndex) {
            return $a <=> $b;
        }

        return $aIndex <=> $bIndex;
    }

    public function getMajorVersion(): int
    {
        return (int) explode('.', $this->normalizedVersion, 2)[0];
    }
}
