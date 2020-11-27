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

use Doctrine\ORM\Mapping as ORM;

/**
 * @ORM\MappedSuperclass()
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
abstract class PackageLink
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(length=191)
     */
    private $packageName;

    /**
     * @ORM\Column(type="text")
     */
    private $packageVersion;

    /**
     * Base property holding the version - this must remain protected since it
     * is redefined with an annotation in the child class
     */
    protected $version;

    public function toArray()
    {
        return array($this->getPackageName() => $this->getPackageVersion());
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
     * @param Version $version
     */
    public function setVersion(Version $version)
    {
        $this->version = $version;
    }

    /**
     * Get version
     *
     * @return Version
     */
    public function getVersion()
    {
        return $this->version;
    }

    public function __toString()
    {
        return $this->packageName.' '.$this->packageVersion;
    }
}
