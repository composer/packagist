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

#[ORM\Entity(repositoryClass: SuggesterRepository::class)]
#[ORM\Table(name: 'suggester')]
#[ORM\Index(name: 'all_suggesters', columns: ['packageName'])]
class Suggester
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Package::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Package $package;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 191)]
    private string $packageName;

    public function __construct(Package $sourcePackage, string $targetPackageName)
    {
        $this->package = $sourcePackage;
        $this->packageName = $targetPackageName;
    }

    public function getPackage(): Package
    {
        return $this->package;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }
}
