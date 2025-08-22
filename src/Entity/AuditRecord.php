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

use App\Audit\AuditRecordType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
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
    public readonly \DateTimeImmutable $datetime;

    private function __construct(
        #[ORM\Column]
        public readonly AuditRecordType $type,

        /** @var array<string, mixed> */
        #[ORM\Column(type: Types::JSON)]
        public readonly array $attributes,
        #[ORM\Column(nullable: true)]
        public readonly ?int $userId = null,
        #[ORM\Column(nullable: true)]
        public readonly ?string $vendor = null,
        #[ORM\Column(nullable: true)]
        public readonly ?int $packageId = null,
    ) {
        $this->id = new Ulid();
        $this->datetime = new \DateTimeImmutable();
    }

    public static function packageCreated(Package $package, ?User $user): self
    {
        return new self(AuditRecordType::PackageCreated, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($user)], $user?->getId(), $package->getVendor(), $package->getId());
    }

    public static function packageDeleted(Package $package, ?User $user): self
    {
        return new self(AuditRecordType::PackageDeleted, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($user, 'automation')], $user?->getId(), $package->getVendor(), $package->getId());
    }

    public static function canonicalUrlChange(Package $package, ?User $user, string $oldRepository): self
    {
        return new self(AuditRecordType::CanonicalUrlChange, ['name' => $package->getName(), 'repository_from' => $oldRepository, 'repository_to' => $package->getRepository(), 'actor' => self::getUserData($user)], $user?->getId(), $package->getVendor(), $package->getId());
    }

    public static function versionDeleted(Version $version, ?User $user): self
    {
        $package = $version->getPackage();

        return new self(AuditRecordType::VersionDeleted, ['name' => $package->getName(), 'version' => $version->getVersion(), 'actor' => self::getUserData($user, 'automation')], $user?->getId(), $package->getVendor(), $package->getId());
    }

    public static function versionReferenceChange(Version $version, ?string $oldSourceReference, ?string $oldDistReference): self
    {
        $package = $version->getPackage();

        return new self(
            AuditRecordType::VersionReferenceChange,
            ['name' => $package->getName(), 'version' => $version->getVersion(), 'source_from' => $oldSourceReference, 'source_to' => $version->getSource()['reference'] ?? null, 'dist_from' => $oldDistReference, 'dist_to' => $version->getDist()['reference'] ?? null],
            vendor: $package->getVendor(),
            packageId: $package->getId()
        );
    }

    /**
     * @return array{id: int, username: string}|string
     */
    private static function getUserData(?User $user, string $fallbackString = 'unknown'): array|string
    {
        if ($user === null) {
            return $fallbackString;
        }

        return ['id' => $user->getId(), 'username' => $user->getUsername()];
    }
}
