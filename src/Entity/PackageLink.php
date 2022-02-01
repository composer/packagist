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
    private int $id;

    /**
     * @ORM\Column(length=191)
     */
    private string $packageName;

    /**
     * @ORM\Column(type="text")
     */
    private string $packageVersion;

    /**
     * Base property holding the version - this must remain protected since it
     * is redefined with an annotation in the child class
     */
    protected Version|null $version = null;

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [$this->getPackageName() => $this->getPackageVersion()];
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setPackageName(string $packageName): void
    {
        $this->packageName = $packageName;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function setPackageVersion(string $packageVersion): void
    {
        $this->packageVersion = $packageVersion;
    }

    public function getPackageVersion(): string
    {
        return $this->packageVersion;
    }

    public function setVersion(Version $version): void
    {
        $this->version = $version;
    }

    public function getVersion(): Version|null
    {
        return $this->version;
    }

    public function __toString()
    {
        return $this->packageName.' '.$this->packageVersion;
    }
}
