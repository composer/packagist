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
 * @ORM\Entity(repositoryClass="App\Entity\VendorRepository")
 * @ORM\Table(
 *     name="vendor",
 *     indexes={
 *         @ORM\Index(name="verified_idx",columns={"verified"})
 *     }
 * )
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class Vendor
{
    /**
     * Unique vendor name
     *
     * @ORM\Id
     * @ORM\Column(length=191)
     */
    private string $name;

    /**
     * @ORM\Column(type="boolean")
     */
    private bool $verified = false;

    public function __construct(string $name)
    {
        $this->name = $name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function setVerified(bool $verified): void
    {
        $this->verified = $verified;
    }

    public function getVerified(): bool
    {
        return $this->verified;
    }
}
