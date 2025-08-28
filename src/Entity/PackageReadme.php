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
#[ORM\Table(name: 'package_readme')]
class PackageReadme
{
    public function __construct(
        #[ORM\Id]
        #[ORM\OneToOne(targetEntity: Package::class)]
        #[ORM\JoinColumn(referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public Package $package,

        #[ORM\Column(type: 'text', nullable: false)]
        public string $contents,
    ) {
    }

    /**
     * Get contents with transformations that should not be done in the stored contents as they might not be valid in the long run
     */
    public string $optimizedContents {
        get {
            return str_replace(['<img src="https://raw.github.com/', '<img src="https://raw.githubusercontent.com/'], '<img src="https://rawcdn.githack.com/', $this->contents);
        }
    }
}
