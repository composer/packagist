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
use Symfony\Component\Validator\ExecutionContext;
use Doctrine\Common\Collections\ArrayCollection;

/**
 * @ORM\Entity
 * @ORM\Table(name="author")
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Author
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
     * @ORM\Column(type="text", nullable="true")
     */
    private $name;

    /**
     * @ORM\Column(type="text", nullable="true")
     */
    private $email;

    /**
     * @ORM\Column(type="text", nullable="true")
     */
    private $homepage;

    /**
     * @ORM\ManyToMany(targetEntity="Packagist\WebBundle\Entity\Version", mappedBy="tags")
     */
    private $versions;

    /**
     * @ORM\ManyToOne(targetEntity="Packagist\WebBundle\Entity\User", inversedBy="authors")
     */
    private $owner;

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
        $this->versions = new ArrayCollection();
        $this->createdAt = new \DateTime;
    }

    public function toArray()
    {
        return array(
            'name' => $this->getName(),
            'email' => $this->getEmail(),
            'homepage' => $this->getHomepage(),
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
     * @param Packagist\WebBundle\Entity\Version $version
     */
    public function addVersion(Version $version)
    {
        $this->versions[] = $version;
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
     * Set email
     *
     * @param text $email
     */
    public function setEmail($email)
    {
        $this->email = $email;
    }

    /**
     * Get email
     *
     * @return text
     */
    public function getEmail()
    {
        return $this->email;
    }

    /**
     * Set homepage
     *
     * @param text $homepage
     */
    public function setHomepage($homepage)
    {
        $this->homepage = $homepage;
    }

    /**
     * Get homepage
     *
     * @return text
     */
    public function getHomepage()
    {
        return $this->homepage;
    }

    /**
     * Set owner
     *
     * @param Packagist\WebBundle\Entity\User $owner
     */
    public function setOwner(User $owner)
    {
        $this->owner = $owner;
    }

    /**
     * Get owner
     *
     * @return Packagist\WebBundle\Entity\User
     */
    public function getOwner()
    {
        return $this->owner;
    }
}