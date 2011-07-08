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
 * @ORM\Table(name="requirement")
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Requirement
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column()
     */
    private $packageName;

    /**
     * @ORM\Column()
     */
    private $packageVersion;

    /**
     * @ORM\ManyToOne(targetEntity="Packagist\WebBundle\Entity\Version", inversedBy="requirements")
     */
    private $version;

    public function toArray()
    {
        return array($this->packageName => $this->packageVersion);
    }

    public function setId($id)
    {
        $this->id = $id;
    }

    public function getId()
    {
        return $this->id;
    }

    /**
     * Set packageName
     *
     * @param string $packageName
     */
    public function setPackageName($packageName)
    {
        $this->packageName = $packageName;
    }

    /**
     * Get packageName
     *
     * @return string
     */
    public function getPackageName()
    {
        return $this->packageName;
    }

    /**
     * Set packageVersion
     *
     * @param string $packageVersion
     */
    public function setPackageVersion($packageVersion)
    {
        $this->packageVersion = $packageVersion;
    }

    /**
     * Get packageVersion
     *
     * @return string
     */
    public function getPackageVersion()
    {
        return $this->packageVersion;
    }

    /**
     * Set version
     *
     * @param Packagist\WebBundle\Entity\Version $version
     */
    public function setVersion(\Packagist\WebBundle\Entity\Version $version)
    {
        $this->version = $version;
    }

    /**
     * Get version
     *
     * @return Packagist\WebBundle\Entity\Version
     */
    public function getVersion()
    {
        return $this->version;
    }
}