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
 *     uniqueConstraints={@ORM\UniqueConstraint(name="pkg_ver_idx",columns={"package_id","version"})}
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
     * @ORM\Column(nullable="true")
     */
    private $license;

//    /**
//     * @ORM\ManyToMany(targetEntity="User")
//     */
//    private $authors;

    /**
     * JSON object of source spec
     *
     * @ORM\Column(type="text")
     * @Assert\NotBlank()
     */
    private $source;

    /**
     * JSON object of requirements
     *
     * @ORM\Column(type="text", name="requires")
     * @Assert\NotBlank()
     */
    private $require;

//    dist (later)

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
        foreach ($this->tags as $tag) {
            $tags[] = $tag->getName();
        }
        return array(
            'name' => $this->name,
            'description' => $this->description,
            'keywords' => $tags,
            'homepage' => $this->homepage,
            'version' => $this->version,
            'license' => $this->license,
            'authors' => array(),
            'require' => $this->getRequire(),
            'source' => $this->getSource(),
            'time' => $this->releasedAt->format('Y-m-d\TH:i:s'),
            'dist' => array(),
        );
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
        if (preg_match('#^([a-z-]+) (\S+)$#', $source, $m)) {
            $this->source = json_encode(array('type' => $m[1], 'url' => $m[2]));
        }
    }

    /**
     * Get source
     *
     * @return text $source
     */
    public function getSource()
    {
        return json_decode($this->source);
    }

    /**
     * Set require
     *
     * @param text $require
     */
    public function setRequire($require)
    {
        if (preg_match_all('#^(\S+) (\S+)\r?\n?$#m', $require, $m)) {
            $requires = array();
            foreach ($m[1] as $idx => $package) {
                $requires[$package] = $m[2][$idx];
            }
            $this->require = json_encode($requires);
        }
    }

    /**
     * Get require
     *
     * @return text $require
     */
    public function getRequire()
    {
        return json_decode($this->require);
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
    public function setPackage(\Packagist\WebBundle\Entity\Package $package)
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
    public function addTags(\Packagist\WebBundle\Entity\Tag $tags)
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
}