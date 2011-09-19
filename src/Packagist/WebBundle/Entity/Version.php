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

/**
 * @ORM\Entity
 * @ORM\Table(
 *     name="package_version",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="pkg_ver_idx",columns={"package_id","version","versionType","development"})}
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
     * @ORM\Column(type="text", nullable="true")
     */
    private $description;

    /**
     * @ORM\Column(nullable="true")
     */
    private $type;

    /**
     * @ORM\Column(type="array", nullable="true")
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
     * @ORM\Column(nullable="true")
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
    private $versionType;

    /**
     * @ORM\Column(type="boolean")
     * @Assert\NotBlank()
     */
    private $development;

    /**
     * @ORM\Column(nullable="true")
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
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\Requirement", mappedBy="version")
     */
    private $requirements;

    /**
     * @ORM\Column(type="text")
     */
    private $source;

    /**
     * @ORM\Column(type="text")
     */
    private $dist;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime")
     */
    private $updatedAt;

    /**
     * @ORM\Column(type="datetime")
     * @Assert\NotBlank()
     */
    private $releasedAt;

    public function __construct()
    {
        $this->tags = new \Doctrine\Common\Collections\ArrayCollection();
        $this->createdAt = new \DateTime;
        $this->updatedAt = new \DateTime;
    }

    public function toArray()
    {
        $tags = array();
        foreach ($this->getTags() as $tag) {
            $tags[] = $tag->getName();
        }
        $authors = array();
        foreach ($this->getAuthors() as $author) {
            $authors[] = $author->toArray();
        }
        $requirements = array();
        foreach ($this->getRequirements() as $requirement) {
            $requirement = $requirement->toArray();
            $requirements[key($requirement)] = current($requirement);
        }
        return array(
            'name' => $this->name,
            'description' => $this->description,
            'keywords' => $tags,
            'homepage' => $this->homepage,
            'version' => $this->version . ($this->versionType ? '-'.$this->versionType : '') . ($this->development ? '-dev':''),
            'license' => $this->license,
            'authors' => $authors,
            'require' => $requirements,
            'source' => $this->getSource(),
            'time' => $this->releasedAt ? $this->releasedAt->format('Y-m-d\TH:i:sP') : null,
            'dist' => $this->getDist(),
            'type' => $this->type,
            'extra' => $this->extra,
        );
    }

    public function equals(Version $version)
    {
        return $version->getName() === $this->getName()
            && $version->getVersion() === $this->getVersion()
            && $version->getVersionType() === $this->getVersionType()
            && $version->getDevelopment() === $this->getDevelopment();
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

    /**
     * Set description
     *
     * @param text $description
     */
    public function setDescription($description)
    {
        $this->description = $description;
    }

    /**
     * Get description
     *
     * @return text $description
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
        $this->version = ltrim($version, 'vV.');
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
     * Set license
     *
     * @param string $license
     */
    public function setLicense($license)
    {
        $this->license = $license;
    }

    /**
     * Get license
     *
     * @return string $license
     */
    public function getLicense()
    {
        return $this->license;
    }

    /**
     * Set source
     *
     * @param text $source
     */
    public function setSource($source)
    {
        $this->source = json_encode($source);
    }

    /**
     * Get source
     *
     * @return text $source
     */
    public function getSource()
    {
        return json_decode($this->source, true);
    }

    /**
     * Set dist
     *
     * @param text $dist
     */
    public function setDist($dist)
    {
        $this->dist = json_encode($dist);
    }

    /**
     * Get dist
     *
     * @return text
     */
    public function getDist()
    {
        return json_decode($this->dist, true);
    }

    /**
     * Set createdAt
     *
     * @param datetime $createdAt
     */
    public function setCreatedAt($createdAt)
    {
        $this->createdAt = $createdAt;
    }

    /**
     * Get createdAt
     *
     * @return datetime $createdAt
     */
    public function getCreatedAt()
    {
        return $this->createdAt;
    }

    /**
     * Set releasedAt
     *
     * @param datetime $releasedAt
     */
    public function setReleasedAt($releasedAt)
    {
        $this->releasedAt = $releasedAt;
    }

    /**
     * Get releasedAt
     *
     * @return datetime $releasedAt
     */
    public function getReleasedAt()
    {
        return $this->releasedAt;
    }

    /**
     * Set package
     *
     * @param Packagist\WebBundle\Entity\Package $package
     */
    public function setPackage(Package $package)
    {
        $this->package = $package;
    }

    /**
     * Get package
     *
     * @return Packagist\WebBundle\Entity\Package $package
     */
    public function getPackage()
    {
        return $this->package;
    }

    /**
     * Add tags
     *
     * @param Packagist\WebBundle\Entity\Tag $tags
     */
    public function addTags(Tag $tags)
    {
        $this->tags[] = $tags;
    }

    /**
     * Get tags
     *
     * @return Doctrine\Common\Collections\Collection $tags
     */
    public function getTags()
    {
        return $this->tags;
    }

    public function setTagsText($text)
    {
        $tags = array();
        if (trim($text)) {
            $tags = preg_split('#[\s,]+#', trim($text));
            $tags = array_map(function($el) {
                return trim(ltrim($el, '#'), '"\'');
            }, $tags);
            $uniqueTags = array();
            foreach ($tags as $tag) {
                if ($tag && !isset($uniqueTags[strtolower($tag)])) {
                    $uniqueTags[strtolower($tag)] = $tag;
                }
            }
            $tags = array_values($uniqueTags);
        }

        foreach ($this->tags as $k => $tag) {
            if (false !== ($idx = array_search($tag->getName(), $tags))) {
                unset($tags[$idx]);
            } else {
                unset($this->tags[$k]);
            }
        }

        foreach ($tags as $tag) {
            $this->addTags($this->getTagEntity($tag));
        }
    }

    public function setEntityManager($em)
    {
        $this->em = $em;
    }

    protected function getTagEntity($name)
    {
        return Tag::getByName($this->em, $name, true);
    }

    public function getTagsText()
    {
        $tags = array();
        foreach ($this->tags as $tag) {
            $tags[] = $tag->getName();
        }
        return implode(', ', $tags);
    }

    /**
     * Set updatedAt
     *
     * @param datetime $updatedAt
     */
    public function setUpdatedAt($updatedAt)
    {
        $this->updatedAt = $updatedAt;
    }

    /**
     * Get updatedAt
     *
     * @return datetime $updatedAt
     */
    public function getUpdatedAt()
    {
        return $this->updatedAt;
    }

    /**
     * Add authors
     *
     * @param Packagist\WebBundle\Entity\Author $authors
     */
    public function addAuthors(Author $authors)
    {
        $this->authors[] = $authors;
    }

    /**
     * Get authors
     *
     * @return Doctrine\Common\Collections\Collection
     */
    public function getAuthors()
    {
        return $this->authors;
    }

    /**
     * Add requirements
     *
     * @param Packagist\WebBundle\Entity\Requirement $requirements
     */
    public function addRequirements(Requirement $requirements)
    {
        $this->requirements[] = $requirements;
    }

    /**
     * Get requirements
     *
     * @return Doctrine\Common\Collections\Collection
     */
    public function getRequirements()
    {
        return $this->requirements;
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
     * Set versionType
     *
     * @param string $versionType
     */
    public function setVersionType($versionType)
    {
        $this->versionType = $versionType;
    }

    /**
     * Get versionType
     *
     * @return string
     */
    public function getVersionType()
    {
        return $this->versionType;
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
}