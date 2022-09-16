<?php

namespace App\Entity;

use App\Audit\AuditRecordType;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Doctrine\ORM\Query\Expr\Select;
use Symfony\Component\Uid\Ulid;

#[ORM\Entity(repositoryClass: AuditRecordRepository::class)]
#[ORM\Table(name: 'audit_log')]
#[ORM\Index(name: 'type_idx', columns: ['type'])]
#[ORM\Index(name: 'datetime_idx', columns: ['datetime'])]
#[ORM\Index(name: 'vendor_idx', columns: ['vendor'])]
#[ORM\Index(name: 'package_idx', columns: ['packageId'])]
class AuditRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid')]
    public readonly Ulid $id;

    #[ORM\Column]
    public readonly DateTimeImmutable $datetime;

    private function __construct(
        #[ORM\Column]
        public readonly AuditRecordType $type,

        /** @var array<string, mixed> */
        #[ORM\Column(type: Types::JSON)]
        public readonly array $attributes,

        #[ORM\Column(nullable: true)]
        public readonly int|null $userId = null,

        #[ORM\Column(nullable: true)]
        public readonly string|null $vendor = null,

        #[ORM\Column(nullable: true)]
        public readonly int|null $packageId = null,
    ) {
        $this->id = new Ulid();
        $this->datetime = new DateTimeImmutable();
    }

    public static function packageCreated(Package $package, User|null $user): self
    {
        return new self(AuditRecordType::PackageCreated, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($user)], $user?->getId(), $package->getVendor(), $package->getId());
    }

    public static function packageDeleted(Package $package, User|null $user): self
    {
        return new self(AuditRecordType::PackageDeleted, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($user, 'automation')], $user?->getId(), $package->getVendor(), $package->getId());
    }

    public static function canonicalUrlChange(Package $package, User|null $user, string $oldRepository): self
    {
        return new self(AuditRecordType::CanonicalUrlChange, ['name' => $package->getName(), 'repository_from' => $oldRepository, 'repository_to' => $package->getRepository(), 'actor' => self::getUserData($user)], $user?->getId(), $package->getVendor(), $package->getId());
    }

    /**
     * @return array{id: int, username: string}|string
     */
    private static function getUserData(User|null $user, string $fallbackString = 'unknown'): array|string
    {
        if ($user === null) {
            return $fallbackString;
        }

        return ['id' => $user->getId(), 'username' => $user->getUsername()];
    }
}
