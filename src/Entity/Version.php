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
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;

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
    private $id;

    /**
     * @ORM\Column
     * @Assert\NotBlank()
     */
    private $name;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $description;

    /**
     * @ORM\Column(nullable=true)
     */
    private $type;

    /**
     * @ORM\Column(nullable=true)
     */
    private $targetDir;

    /**
     * @ORM\Column(type="array", nullable=true)
     */
    private $extra = array();

    /**
     * @ORM\ManyToMany(targetEntity="App\Entity\Tag", inversedBy="versions")
     * @ORM\JoinTable(name="version_tag",
     *     joinColumns={@ORM\JoinColumn(name="version_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="tag_id", referencedColumnName="id")}
     * )
     */
    private $tags;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Package", fetch="EAGER", inversedBy="versions")
     * @Assert\Type(type="App\Entity\Package")
     */
    private $package;

    /**
     * @ORM\Column(nullable=true)
     * @Assert\Url()
     */
    private $homepage;

    /**
     * @ORM\Column
     * @Assert\NotBlank()
     */
    private $version;

    /**
     * @ORM\Column(length=191)
     * @Assert\NotBlank()
     */
    private $normalizedVersion;

    /**
     * @ORM\Column(type="boolean")
     * @Assert\NotBlank()
     */
    private $development;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $license;

    /**
     * Deprecated relation table, use the authorJson property instead
     *
     * @ORM\ManyToMany(targetEntity="App\Entity\Author", inversedBy="versions")
     * @ORM\JoinTable(name="version_author",
     *     joinColumns={@ORM\JoinColumn(name="version_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="author_id", referencedColumnName="id")}
     * )
     */
    private $authors;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\RequireLink", mappedBy="version")
     */
    private $require;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\ReplaceLink", mappedBy="version")
     */
    private $replace;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\ConflictLink", mappedBy="version")
     */
    private $conflict;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\ProvideLink", mappedBy="version")
     */
    private $provide;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\DevRequireLink", mappedBy="version")
     */
    private $devRequire;

    /**
     * @ORM\OneToMany(targetEntity="App\Entity\SuggestLink", mappedBy="version")
     */
    private $suggest;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $source;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $dist;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $autoload;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $binaries;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $includePaths;

    /**
     * @ORM\Column(type="text", nullable=true)
     */
    private $support;

    /**
     * @ORM\Column(type="json", nullable=true)
     */
    private $funding;

    /**
     * @ORM\Column(name="authors", type="json", nullable=true)
     */
    private $authorJson;

    /**
     * @ORM\Column(name="defaultBranch", type="boolean", options={"default": false})
     */
    private $isDefaultBranch = false;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $softDeletedAt;

    /**
     * @ORM\Column(type="datetime")
     */
    private $updatedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $releasedAt;

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
        $this->createdAt = new \DateTime;
        $this->updatedAt = new \DateTime;
    }

    public function toArray(array $versionData, bool $serializeForApi = false)
    {
        if (isset($versionData[$this->id]['tags'])) {
            $tags = $versionData[$this->id]['tags'];
        } else {
            $tags = array();
            foreach ($this->getTags() as $tag) {
                /** @var $tag Tag */
                $tags[] = $tag->getName();
            }
        }

        if (!is_null($this->getAuthorJson())) {
            $authors = $this->getAuthorJson();
        } else {
            if (isset($versionData[$this->id]['authors'])) {
                $authors = $versionData[$this->id]['authors'];
            } else {
                $authors = array();
                foreach ($this->getAuthors() as $author) {
                    /** @var $author Author */
                    $authors[] = $author->toArray();
                }
            }
        }
        foreach ($authors as &$author) {
            uksort($author, [$this, 'sortAuthorKeys']);
        }
        unset($author);

        $data = array(
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
        );

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

        $supportedLinkTypes = array(
            'require'    => 'require',
            'devRequire' => 'require-dev',
            'suggest'    => 'suggest',
            'conflict'   => 'conflict',
            'provide'    => 'provide',
            'replace'    => 'replace',
        );

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

    /**
     * Get id
     *
     * @return string $id
     */
    public function getId()
    {
        return $this->id;
    }

    /**
     * Set name
     *
     * @param string $name
     */
    public function setName($name)
    {
        $this->name = $name;
    }

    /**
     * Get name
     *
     * @return string $name
     */
    public function getName()
    {
        return $this->name;
    }

    public function getNames(array $versionData = null)
    {
        $names = array(
            strtolower($this->name) => true
        );

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

    /**
     * Set description
     *
     * @param string $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * Get description
     *
     * @return string $description
     */
    public function getDescription()
    {
        return $this->description;
    }

    /**
     * Set homepage
     *
     * @param string $homepage
     */
    public function setHomepage($homepage)
    {
        $this->homepage = $homepage;
    }

    /**
     * Get homepage
     *
     * @return string $homepage
     */
    public function getHomepage()
    {
        return $this->homepage;
    }

    /**
     * Set version
     *
     * @param string $version
     */
    public function setVersion($version)
    {
        $this->version = $version;
    }

    /**
     * Get version
     *
     * @return string $version
     */
    public function getVersion()
    {
        return $this->version;
    }

    /**
     * @return string
     */
    public function getRequireVersion()
    {
        return preg_replace('{^v(\d)}', '$1', str_replace('.x-dev', '.*@dev', $this->getVersion()));
    }

    /**
     * Set normalizedVersion
     *
     * @param string $normalizedVersion
     */
    public function setNormalizedVersion($normalizedVersion)
    {
        $this->normalizedVersion = $normalizedVersion;
    }

    /**
     * Get normalizedVersion
     *
     * @return string $normalizedVersion
     */
    public function getNormalizedVersion()
    {
        return $this->normalizedVersion;
    }

    /**
     * Set license
     *
     * @param array $license
     */
    public function setLicense(array $license)
    {
        $this->license = json_encode($license);
    }

    /**
     * Get license
     *
     * @return array $license
     */
    public function getLicense()
    {
        return json_decode($this->license, true);
    }

    /**
     * Set source
     *
     * @param array $source
     */
    public function setSource($source)
    {
        $this->source = null === $source ? $source : json_encode($source);
    }

    /**
     * Get source
     *
     * @return array|null
     */
    public function getSource()
    {
        return json_decode($this->source, true);
    }

    /**
     * Set dist
     *
     * @param array $dist
     */
    public function setDist($dist)
    {
        $this->dist = null === $dist ? $dist : json_encode($dist);
    }

    /**
     * Get dist
     *
     * @return array|null
     */
    public function getDist()
    {
        return json_decode($this->dist, true);
    }

    /**
     * Set autoload
     *
     * @param array $autoload
     */
    public function setAutoload($autoload)
    {
        $this->autoload = json_encode($autoload);
    }

    /**
     * Get autoload
     *
     * @return array|null
     */
    public function getAutoload()
    {
        return json_decode($this->autoload, true);
    }

    /**
     * Set binaries
     *
     * @param array $binaries
     */
    public function setBinaries($binaries)
    {
        $this->binaries = null === $binaries ? $binaries : json_encode($binaries);
    }

    /**
     * Get binaries
     *
     * @return array|null
     */
    public function getBinaries()
    {
        return json_decode($this->binaries, true);
    }

    /**
     * Set include paths.
     *
     * @param array $paths
     */
    public function setIncludePaths($paths)
    {
        $this->includePaths = $paths ? json_encode($paths) : null;
    }

    /**
     * Get include paths.
     *
     * @return array|null
     */
    public function getIncludePaths()
    {
        return json_decode($this->includePaths, true);
    }

    /**
     * Set support
     *
     * @param array $support
     */
    public function setSupport($support)
    {
        $this->support = $support ? json_encode($support) : null;
    }

    /**
     * Get support
     *
     * @return array|null
     */
    public function getSupport()
    {
        return json_decode($this->support, true);
    }

    /**
     * Set Funding
     *
     * @param array $funding
     */
    public function setFunding($funding)
    {
        $this->funding = $funding;
    }

    /**
     * Get funding
     *
     * @return array|null
     */
    public function getFunding()
    {
        return $this->funding;
    }

    /**
     * Get funding, sorted to help the V2 metadata compression algo
     */
    public function getFundingSorted()
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

    /**
     * Set createdAt
     *
     * @param \DateTime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Get createdAt
     *
     * @return \DateTime
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Set releasedAt
     *
     * @param \DateTime $releasedAt
     */
    public function setReleasedAt($releasedAt)
    {
        $this->releasedAt = $releasedAt;
    }

    /**
     * Get releasedAt
     *
     * @return \DateTime
     */
    public function getReleasedAt()
    {
        return $this->releasedAt;
    }

    /**
     * Set package
     *
     * @param Package $package
     */
    public function setPackage(Package $package)
    {
        $this->package = $package;
    }

    /**
     * Get package
     *
     * @return Package
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * Get tags
     *
     * @return Tag[]
     */
    public function getTags()
    {
        return $this->tags;
    }

    /**
     * Set updatedAt
     *
     * @param \DateTime $updatedAt
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Get updatedAt
     *
     * @return \DateTime $updatedAt
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * Set softDeletedAt
     *
     * @param \DateTime|null $softDeletedAt
     */
    public function setSoftDeletedAt($softDeletedAt)
    {
        $this->softDeletedAt = $softDeletedAt;
    }

    /**
     * Get softDeletedAt
     *
     * @return \DateTime|null $softDeletedAt
     */
    public function getSoftDeletedAt()
    {
        return $this->softDeletedAt;
    }

    /**
     * Get authors
     *
     * @return Author[]
     */
    public function getAuthors()
    {
        return $this->authors;
    }

    public function getAuthorJson(): ?array
    {
        return $this->authorJson;
    }

    public function setAuthorJson(?array $authors): void
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

    /**
     * Set type
     *
     * @param string $type
     */
    public function setType($type)
    {
        $this->type = $type;
    }

    /**
     * Get type
     *
     * @return string
     */
    public function getType()
    {
        return $this->type;
    }

    /**
     * Set targetDir
     *
     * @param string $targetDir
     */
    public function setTargetDir($targetDir)
    {
        $this->targetDir = $targetDir;
    }

    /**
     * Get targetDir
     *
     * @return string
     */
    public function getTargetDir()
    {
        return $this->targetDir;
    }

    /**
     * Set extra
     *
     * @param array $extra
     */
    public function setExtra($extra)
    {
        $this->extra = $extra;
    }

    /**
     * Get extra
     *
     * @return array
     */
    public function getExtra()
    {
        return $this->extra;
    }

    /**
     * Set development
     *
     * @param Boolean $development
     */
    public function setDevelopment($development)
    {
        $this->development = $development;
    }

    /**
     * Get development
     *
     * @return Boolean
     */
    public function getDevelopment()
    {
        return $this->development;
    }

    /**
     * @return Boolean
     */
    public function isDevelopment()
    {
        return $this->getDevelopment();
    }

    /**
     * Add tag
     *
     * @param Tag $tag
     */
    public function addTag(Tag $tag)
    {
        $this->tags[] = $tag;
    }

    /**
     * Add authors
     *
     * @param Author $author
     */
    public function addAuthor(Author $author)
    {
        $this->authors[] = $author;
    }

    /**
     * Add require
     *
     * @param RequireLink $require
     */
    public function addRequireLink(RequireLink $require)
    {
        $this->require[] = $require;
    }

    /**
     * Get require
     *
     * @return RequireLink[]
     */
    public function getRequire()
    {
        return $this->require;
    }

    /**
     * Add replace
     *
     * @param ReplaceLink $replace
     */
    public function addReplaceLink(ReplaceLink $replace)
    {
        $this->replace[] = $replace;
    }

    /**
     * Get replace
     *
     * @return ReplaceLink[]
     */
    public function getReplace()
    {
        return $this->replace;
    }

    /**
     * Add conflict
     *
     * @param ConflictLink $conflict
     */
    public function addConflictLink(ConflictLink $conflict)
    {
        $this->conflict[] = $conflict;
    }

    /**
     * Get conflict
     *
     * @return ConflictLink[]
     */
    public function getConflict()
    {
        return $this->conflict;
    }

    /**
     * Add provide
     *
     * @param ProvideLink $provide
     */
    public function addProvideLink(ProvideLink $provide)
    {
        $this->provide[] = $provide;
    }

    /**
     * Get provide
     *
     * @return ProvideLink[]
     */
    public function getProvide()
    {
        return $this->provide;
    }

    /**
     * Add devRequire
     *
     * @param DevRequireLink $devRequire
     */
    public function addDevRequireLink(DevRequireLink $devRequire)
    {
        $this->devRequire[] = $devRequire;
    }

    /**
     * Get devRequire
     *
     * @return DevRequireLink[]
     */
    public function getDevRequire()
    {
        return $this->devRequire;
    }

    /**
     * Add suggest
     *
     * @param SuggestLink $suggest
     */
    public function addSuggestLink(SuggestLink $suggest)
    {
        $this->suggest[] = $suggest;
    }

    /**
     * Get suggest
     *
     * @return SuggestLink[]
     */
    public function getSuggest()
    {
        return $this->suggest;
    }

    /**
     * @return Boolean
     */
    public function hasVersionAlias()
    {
        return $this->getDevelopment() && $this->getVersionAlias();
    }

    /**
     * @return string
     */
    public function getVersionAlias()
    {
        $extra = $this->getExtra();

        if (isset($extra['branch-alias'][$this->getVersion()])) {
            $parser = new VersionParser;
            $version = $parser->normalizeBranch(str_replace('-dev', '', $extra['branch-alias'][$this->getVersion()]));
            return preg_replace('{(\.9{7})+}', '.x', $version);
        }

        return '';
    }

    /**
     * @return string
     */
    public function getRequireVersionAlias()
    {
        return str_replace('.x-dev', '.*@dev', $this->getVersionAlias());
    }

    public function __toString()
    {
        return $this->name.' '.$this->version.' ('.$this->normalizedVersion.')';
    }

    private function sortAuthorKeys($a, $b)
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
