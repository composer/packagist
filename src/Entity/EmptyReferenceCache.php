<?php declare(strict_types=1);

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
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
#[ORM\Entity]
#[ORM\Table(name: 'empty_references')]
class EmptyReferenceCache
{
    #[ORM\Id]
    #[ORM\OneToOne(targetEntity: 'App\Entity\Package')]
    private Package $package;

    /**
     * @var list<string>
     */
    #[ORM\Column(type: 'array')]
    private array $emptyReferences = [];

    public function __construct(Package $package)
    {
        $this->package = $package;
    }

    public function setPackage(Package $package): void
    {
        $this->package = $package;
    }

    /**
     * @param list<string> $emptyReferences
     */
    public function setEmptyReferences(array $emptyReferences): void
    {
        $this->emptyReferences = $emptyReferences;
    }

    /**
     * @return list<string>
     */
    public function getEmptyReferences(): array
    {
        return $this->emptyReferences;
    }
}
