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

namespace Packagist\WebBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Validator\Constraints as Assert;
use Doctrine\Common\Collections\ArrayCollection;
use Composer\Package\Version\VersionParser;

/**
 * @ORM\Entity(repositoryClass="Packagist\WebBundle\Entity\VersionRepository")
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
     * @ORM\ManyToMany(targetEntity="Packagist\WebBundle\Entity\Tag", inversedBy="versions")
     * @ORM\JoinTable(name="version_tag",
     *     joinColumns={@ORM\JoinColumn(name="version_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="tag_id", referencedColumnName="id")}
     * )
     */
    private $tags;

    /**
     * @ORM\ManyToOne(targetEntity="Packagist\WebBundle\Entity\Package", fetch="EAGER", inversedBy="versions")
     * @Assert\Type(type="Packagist\WebBundle\Entity\Package")
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
     * @ORM\Column
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
     * @ORM\ManyToMany(targetEntity="Packagist\WebBundle\Entity\Author", inversedBy="versions")
     * @ORM\JoinTable(name="version_author",
     *     joinColumns={@ORM\JoinColumn(name="version_id", referencedColumnName="id")},
     *     inverseJoinColumns={@ORM\JoinColumn(name="author_id", referencedColumnName="id")}
     * )
     */
    private $authors;

    /**
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\RequireLink", mappedBy="version")
     */
    private $require;

    /**
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\ReplaceLink", mappedBy="version")
     */
    private $replace;

    /**
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\ConflictLink", mappedBy="version")
     */
    private $conflict;

    /**
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\ProvideLink", mappedBy="version")
     */
    private $provide;

    /**
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\DevRequireLink", mappedBy="version")
     */
    private $devRequire;

    /**
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\SuggestLink", mappedBy="version")
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
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

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

    public function toArray()
    {
        $tags = array();
        foreach ($this->getTags() as $tag) {
            /** @var $tag Tag */
            $tags[] = $tag->getName();
        }
        $authors = array();
        foreach ($this->getAuthors() as $author) {
            /** @var $author Author */
            $authors[] = $author->toArray();
        }

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

        foreach ($supportedLinkTypes as $method => $linkType) {
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

    public function getNames()
    {
        $names = array(
            strtolower($this->name) => true
        );

        foreach ($this->getReplace() as $link) {
            $names[strtolower($link->getPackageName())] = true;
        }

        foreach ($this->getProvide() as $link) {
            $names[strtolower($link->getPackageName())] = true;
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
     * @return \DateTime $createdAt
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
     * @return \DateTime $releasedAt
     */
    public function getReleasedAt()
    {
        return $this->releasedAt;
    }

    /**
     * Set package
     *
     * @param \Packagist\WebBundle\Entity\Package $package
     */
    public function setPackage(Package $package)
    {
        $this->package = $package;
    }

    /**
     * Get package
     *
     * @return \Packagist\WebBundle\Entity\Package $package
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * Get tags
     *
     * @return \Doctrine\Common\Collections\Collection $tags
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
     * Get authors
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getAuthors()
    {
        return $this->authors;
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
     * @param \Packagist\WebBundle\Entity\Tag $tag
     */
    public function addTag(Tag $tag)
    {
        $this->tags[] = $tag;
    }

    /**
     * Add authors
     *
     * @param \Packagist\WebBundle\Entity\Author $author
     */
    public function addAuthor(Author $author)
    {
        $this->authors[] = $author;
    }

    /**
     * Add require
     *
     * @param \Packagist\WebBundle\Entity\RequireLink $require
     */
    public function addRequireLink(RequireLink $require)
    {
        $this->require[] = $require;
    }

    /**
     * Get require
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getRequire()
    {
        return $this->require;
    }

    /**
     * Add replace
     *
     * @param \Packagist\WebBundle\Entity\ReplaceLink $replace
     */
    public function addReplaceLink(ReplaceLink $replace)
    {
        $this->replace[] = $replace;
    }

    /**
     * Get replace
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getReplace()
    {
        return $this->replace;
    }

    /**
     * Add conflict
     *
     * @param \Packagist\WebBundle\Entity\ConflictLink $conflict
     */
    public function addConflictLink(ConflictLink $conflict)
    {
        $this->conflict[] = $conflict;
    }

    /**
     * Get conflict
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getConflict()
    {
        return $this->conflict;
    }

    /**
     * Add provide
     *
     * @param \Packagist\WebBundle\Entity\ProvideLink $provide
     */
    public function addProvideLink(ProvideLink $provide)
    {
        $this->provide[] = $provide;
    }

    /**
     * Get provide
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getProvide()
    {
        return $this->provide;
    }

    /**
     * Add devRequire
     *
     * @param \Packagist\WebBundle\Entity\DevRequireLink $devRequire
     */
    public function addDevRequireLink(DevRequireLink $devRequire)
    {
        $this->devRequire[] = $devRequire;
    }

    /**
     * Get devRequire
     *
     * @return \Doctrine\Common\Collections\Collection
     */
    public function getDevRequire()
    {
        return $this->devRequire;
    }

    /**
     * Add suggest
     *
     * @param \Packagist\WebBundle\Entity\SuggestLink $suggest
     */
    public function addSuggestLink(SuggestLink $suggest)
    {
        $this->suggest[] = $suggest;
    }

    /**
     * Get suggest
     *
     * @return \Doctrine\Common\Collections\Collection
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
}
