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
 * @ORM\Entity
 * @ORM\Table(name="empty_references")
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class EmptyReferenceCache
{
    /**
     * @ORM\Id
     * @ORM\OneToOne(targetEntity="App\Entity\Package")
     */
    protected $package;

    /**
     * @ORM\Column(type="array")
     */
    protected $emptyReferences = array();

    public function __construct(Package $package)
    {
        $this->package = $package;
    }

    public function setPackage(Package $package)
    {
        $this->package = $package;
    }

    public function setEmptyReferences(array $emptyReferences)
    {
        $this->emptyReferences = $emptyReferences;
    }

    public function getEmptyReferences()
    {
        return $this->emptyReferences;
    }
}
