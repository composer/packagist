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
 *     name="package",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="name_idx",columns={"name"})}
 * )
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Package
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * Unique package name
     *
     * @ORM\Column
     * @Assert\NotBlank()
     */
    private $name;

    /**
     * @ORM\Column(type="text", nullable="true")
     */
    private $description;

    /**
     * @ORM\OneToMany(targetEntity="Packagist\WebBundle\Entity\Version",mappedBy="package")
     */
    private $versions;

    /**
     * @ORM\ManyToMany(targetEntity="User", inversedBy="packages")
     * @ORM\JoinTable(name="maintainers_packages")
     */
    private $maintainers;

    // dist-tags / rel or runtime?

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable="true")
     */
    private $updatedAt;

    public function __construct()
    {
        $this->versions = new \Doctrine\Common\Collections\ArrayCollection();
        $this->createdAt = new \DateTime;
    }

    public function toJson()
    {
        $versions = array();
        foreach ($this->versions as $version) {
            $versions[$version->getVersion()] = $version->toArray();
        }
        $data = array(
            'name' => $this->name,
            'description' => $this->description,
            'dist-tags' => array(),
            'maintainers' => array(),
            'versions' => $versions,
        );
        return json_encode($data);
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
     * Add versions
     *
     * @param Packagist\WebBundle\Entity\Version $versions
     */
    public function addVersions(\Packagist\WebBundle\Entity\Version $versions)
    {
        $this->versions[] = $versions;
    }

    /**
     * Get versions
     *
     * @return string $versions
     */
    public function getVersions()
    {
        return $this->versions;
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
     * Add maintainers
     *
     * @param Packagist\WebBundle\Entity\User $maintainers
     */
    public function addMaintainers(\Packagist\WebBundle\Entity\User $maintainers)
    {
        $this->maintainers[] = $maintainers;
    }

    /**
     * Get maintainers
     *
     * @return Doctrine\Common\Collections\Collection $maintainers
     */
    public function getMaintainers()
    {
        return $this->maintainers;
    }
}