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

use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use DateTimeInterface;

/**
 * @ORM\Entity(repositoryClass="App\Entity\VersionRepository")
 * @ORM\Table(
 *     name="package_version",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="pkg_ver_idx",columns={"package_id","normalizedVersion"})},
 *     indexes={
 *         @ORM\Index(name="release_idx",columns={"releasedAt"}),
 *         @ORM\Index(name="is_devel_idx",columns={"development"})
 *     }
 * )
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Version
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private int $id;

    /**
     * @ORM\Column
     * @Assert\NotBlank()
     */
    private string $name;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string|null $description = null;

    /**
     * @ORM\Column(nullable=true)
     */
    private string|null $type = null;

    /**
     * @ORM\Column(nullable=true)
     */
    private string|null $targetDir = null;

    /**
     * @ORM\Column(type="array", nullable=true)
     * @var array<mixed>
     */
    private array $extra = [];

    /**
     * @ORM\ManyToMany(targetEntity="App\Entity\Tag", inversedBy="versions")
     * @ORM\JoinTable(name="version_tag",
     *     joinColumns={@ORM\JoinColumn(name="version_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="tag_id", referencedColumnName="id")}
     * )
     * @var Collection<int, Tag>&Selectable<int, Tag>
     */
    private Collection $tags;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Package", fetch="EAGER", inversedBy="versions")
     * @Assert\Type(type="App\Entity\Package")
     */
    private Package $package;

    /**
     * @ORM\Column(nullable=true)
     * @Assert\Url()
     */
    private string|null $homepage = null;

    /**
     * @ORM\Column
     * @Assert\NotBlank()
     */
    private string $version;

    /**
     * @ORM\Column(length=191)
     * @Assert\NotBlank()
     */
    private string $normalizedVersion;

    /**
     * @ORM\Column(type="boolean")
     * @Assert\NotBlank()
     */
    private bool $development;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string $license;

    /**
     * Deprecated relation table, use the authorJson property instead
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\Author", inversedBy="versions")
     * @ORM\JoinTable(name="version_author",
     *     joinColumns={@ORM\JoinColumn(name="version_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="author_id", referencedColumnName="id")}
     * )
     * @var Collection<int, Author>&Selectable<int, Author>
     */
    private Collection $authors;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\RequireLink", mappedBy="version")
     * @var Collection<int, RequireLink>&Selectable<int, RequireLink>
     */
    private Collection $require;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\ReplaceLink", mappedBy="version")
     * @var Collection<int, ReplaceLink>&Selectable<int, ReplaceLink>
     */
    private Collection $replace;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\ConflictLink", mappedBy="version")
     * @var Collection<int, ConflictLink>&Selectable<int, ConflictLink>
     */
    private Collection $conflict;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\ProvideLink", mappedBy="version")
     * @var Collection<int, ProvideLink>&Selectable<int, ProvideLink>
     */
    private Collection $provide;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\DevRequireLink", mappedBy="version")
     * @var Collection<int, DevRequireLink>&Selectable<int, DevRequireLink>
     */
    private Collection $devRequire;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\SuggestLink", mappedBy="version")
     * @var Collection<int, SuggestLink>&Selectable<int, SuggestLink>
     */
    private Collection $suggest;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string|null $source = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string|null $dist = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string $autoload;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string|null $binaries = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string|null $includePaths = null;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private string|null $support = null;

    /**
     * @ORM\Column(type="json", nullable=true)
     * @var array<array{type?: string, url?: string}>|null
     */
    private array|null $funding = null;

    /**
     * @ORM\Column(name="authors", type="json", nullable=true)
     * @var array<array{name?: string, homepage?: string, email?: string, role?: string}>|null
     */
    private array|null $authorJson = null;

    /**
     * @ORM\Column(name="defaultBranch", type="boolean", options={"default": false})
     */
    private bool $isDefaultBranch = false;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTimeInterface $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private DateTimeInterface|null $softDeletedAt = null;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTimeInterface $updatedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
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
        $this->authors = new ArrayCollection();
        $this->createdAt = new \DateTimeImmutable;
        $this->updatedAt = new \DateTimeImmutable;
    }

    public function toArray(array $versionData, bool $serializeForApi = false)
    {
        if (isset($versionData[$this->id]['tags'])) {
            $tags = $versionData[$this->id]['tags'];
        } else {
            $tags = [];
            foreach ($this->getTags() as $tag) {
                $tags[] = $tag->getName();
            }
        }

        if (!is_null($this->getAuthorJson())) {
            $authors = $this->getAuthorJson();
        } else {
            if (isset($versionData[$this->id]['authors'])) {
                $authors = $versionData[$this->id]['authors'];
            } else {
                $authors = [];
                foreach ($this->getAuthors() as $author) {
                    $authors[] = $author->toArray();
                }
            }
        }
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
            'require'    => 'require',
            'devRequire' => 'require-dev',
            'suggest'    => 'suggest',
            'conflict'   => 'conflict',
            'provide'    => 'provide',
            'replace'    => 'replace',
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

    public function toV2Array(array $versionData)
    {
        $array = $this->toArray($versionData);

        if ($this->getSupport()) {
            $array['support'] = $this->getSupport();
            ksort($array['support']);
        }

        return $array;
    }

    public function equals(Version $version)
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
    public function getNames(array $versionData = null): array
    {
        $names = [
            strtolower($this->name) => true
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
        $this->license = json_encode($license, JSON_THROW_ON_ERROR);
    }

    public function getLicense(): array
    {
        return json_decode($this->license, true);
    }

    public function setSource(array|null $source): void
    {
        $this->source = null === $source ? $source : json_encode($source, JSON_THROW_ON_ERROR);
    }

    public function getSource(): array|null
    {
        if ($this->source === null) {
            return null;
        }
        return json_decode($this->source, true);
    }

    public function setDist(array|null $dist): void
    {
        $this->dist = null === $dist ? $dist : json_encode($dist, JSON_THROW_ON_ERROR);
    }

    public function getDist(): array|null
    {
        if ($this->dist === null) {
            return null;
        }
        return json_decode($this->dist, true);
    }

    public function setAutoload(array $autoload): void
    {
        $this->autoload = json_encode($autoload, JSON_THROW_ON_ERROR);
    }

    public function getAutoload(): array
    {
        return json_decode($this->autoload, true);
    }

    public function setBinaries(array|null $binaries): void
    {
        $this->binaries = null === $binaries ? $binaries : json_encode($binaries, JSON_THROW_ON_ERROR);
    }

    public function getBinaries(): array|null
    {
        if ($this->binaries === null) {
            return null;
        }
        return json_decode($this->binaries, true);
    }

    public function setIncludePaths(array|null $paths): void
    {
        $this->includePaths = $paths ? json_encode($paths, JSON_THROW_ON_ERROR) : null;
    }

    public function getIncludePaths(): array|null
    {
        if ($this->includePaths === null) {
            return null;
        }
        return json_decode($this->includePaths, true);
    }

    public function setSupport(array|null $support): void
    {
        $this->support = $support ? json_encode($support, JSON_THROW_ON_ERROR) : null;
    }

    public function getSupport(): array|null
    {
        if ($this->support === null) {
            return null;
        }
        return json_decode($this->support, true);
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
        usort($funding, function ($a, $b) {
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
     * Get authors
     *
     * @return Collection<int, Author>&Selectable<int, Author>
     */
    public function getAuthors(): Collection
    {
        return $this->authors;
    }

    /**
     * @return array<array{name?: string, homepage?: string, email?: string, role?: string}>|null
     */
    public function getAuthorJson(): ?array
    {
        return $this->authorJson;
    }

    /**
     * @param array<array{name?: string, homepage?: string, email?: string, role?: string}>|null $authors
     */
    public function setAuthorJson(array|null $authors): void
    {
        $this->authorJson = $authors ?: [];
    }

    public function isDefaultBranch(): bool
    {
        return $this->isDefaultBranch;
    }

    public function setIsDefaultBranch(bool $isDefaultBranch): void
    {
        $this->isDefaultBranch = $isDefaultBranch;
    }

    /**
     * Get authors
     *
     * @return array[]
     */
    public function getAuthorData(): array
    {
        if (!is_null($this->getAuthorJson())) {
            return $this->getAuthorJson();
        }

        $authors = [];
        foreach ($this->getAuthors() as $author) {
            $authors[] = array_filter([
                'name' => $author->getName(),
                'homepage' => $author->getHomepage(),
                'email' => $author->getEmail(),
                'role' => $author->getRole(),
            ]);
        }

        return $authors;
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

    public function addAuthor(Author $author): void
    {
        $this->authors[] = $author;
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
