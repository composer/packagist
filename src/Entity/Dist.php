<?php

declare(strict_types=1);

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
use Symfony\Bridge\Doctrine\Types\UlidType;
use Symfony\Component\Uid\Ulid;

enum DistType: string
{
    case Zip = 'zip';
    case Zstd = 'ztsd';
    case File = 'file';
}

#[ORM\Entity]
#[ORM\Table(name: 'version_dist')]
class Dist
{
    public const MAX_BYTES = 100 * 1024 * 1024;

    #[ORM\Id]
    #[ORM\Column(type: UlidType::NAME, unique: true)]
    public readonly Ulid $id;

    #[ORM\Column(type: 'datetime_immutable')]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\ManyToOne(targetEntity: Package::class)]
    #[ORM\JoinColumn('package_id', nullable: false)]
    public readonly Package $package;

    /**
     * @param array<non-empty-string, non-empty-string> $hashes
     */
    public function __construct(
        #[ORM\OneToOne(targetEntity: Version::class, inversedBy: 'dist')]
        #[ORM\JoinColumn('version_id', nullable: false)]
        public readonly Version $version,
        #[ORM\Column(type: 'integer', options: ['unsigned' => true])]
        public readonly int $bytes,
        #[ORM\Column(type: 'string', enumType: DistType::class)]
        public readonly DistType $type,
        #[ORM\Column(type: 'json')]
        private array $hashes,
    ) {
        $this->id = new Ulid(Ulid::generate($version->getReleasedAt() ?? $version->getCreatedAt()));
        $this->package = $version->getPackage();
        $this->createdAt = new \DateTimeImmutable();
    }

    /**
     * @return array<non-empty-string, non-empty-string>
     */
    public function getHashes(): array
    {
        return $this->hashes;
    }
}
