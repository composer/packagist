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

#[ORM\Entity(repositoryClass: DependentRepository::class)]
#[ORM\Table(name: 'dependent')]
#[ORM\Index(name: 'all_deps', columns: ['package_id', 'packageName'])]
#[ORM\Index(name: 'by_type', columns: ['packageName', 'type'])]
class Dependent
{
    public const TYPE_REQUIRE = 1;
    public const TYPE_REQUIRE_DEV = 2;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: Package::class)]
    #[ORM\JoinColumn(nullable: false)]
    private Package $package;

    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 191)]
    private string $packageName;

    /**
     * @var self::TYPE_*
     */
    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    private int $type;

    /**
     * @param self::TYPE_* $type
     */
    public function __construct(Package $sourcePackage, string $targetPackageName, int $type)
    {
        $this->package = $sourcePackage;
        $this->packageName = $targetPackageName;
        $this->type = $type;
    }

    public function getPackage(): Package
    {
        return $this->package;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    /**
     * @return self::TYPE_*
     */
    public function getType(): int
    {
        return $this->type;
    }
}
