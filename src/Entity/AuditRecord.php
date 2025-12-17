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

use App\Audit\AbandonmentReason;
use App\Audit\AuditRecordType;
use App\Audit\UserRegistrationMethod;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * @phpstan-import-type VersionArray from Version
 */
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
        public readonly ?int $actorId = null,
        #[ORM\Column(nullable: true)]
        public readonly ?string $vendor = null,
        #[ORM\Column(nullable: true)]
        public readonly ?int $packageId = null,
        #[ORM\Column(nullable: true)]
        public readonly ?int $userId = null,
    ) {
        $this->id = new Ulid();
        $this->datetime = new \DateTimeImmutable();
    }

    public static function packageCreated(Package $package, ?User $actor): self
    {
        return new self(AuditRecordType::PackageCreated, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($actor)], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    public static function packageDeleted(Package $package, ?User $actor): self
    {
        return new self(AuditRecordType::PackageDeleted, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($actor, 'automation')], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    public static function canonicalUrlChange(Package $package, ?User $actor, string $oldRepository): self
    {
        return new self(AuditRecordType::CanonicalUrlChanged, ['name' => $package->getName(), 'repository_from' => $oldRepository, 'repository_to' => $package->getRepository(), 'actor' => self::getUserData($actor)], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    /**
     * @param User[] $previousMaintainers
     * @param User[] $currentMaintainers
     */
    public static function packageTransferred(Package $package, ?User $actor, array $previousMaintainers, array $currentMaintainers): self
    {
        $callback = fn (User $user) => self::getUserData($user);
        $previous = array_map($callback, $previousMaintainers);
        $current = array_map($callback, $currentMaintainers);

        return new self(AuditRecordType::PackageTransferred, ['name' => $package->getName(), 'actor' => self::getUserData($actor, 'admin'), 'previous_maintainers' => $previous, 'current_maintainers' => $current], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    /**
     * @param VersionArray $metadata
     */
    public static function versionCreated(Version $version, array $metadata, ?User $actor): self
    {
        $package = $version->getPackage();

        return new self(AuditRecordType::VersionCreated, ['name' => $package->getName(), 'version' => $version->getVersion(), 'actor' => self::getUserData($actor, 'automation'), 'metadata' => $metadata], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    public static function versionDeleted(Version $version, ?User $actor): self
    {
        $package = $version->getPackage();

        return new self(AuditRecordType::VersionDeleted, ['name' => $package->getName(), 'version' => $version->getVersion(), 'actor' => self::getUserData($actor, 'automation')], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    /**
     * @param VersionArray $metadata
     */
    public static function versionReferenceChange(Version $version, ?string $oldSourceReference, ?string $oldDistReference, array $metadata): self
    {
        $package = $version->getPackage();

        return new self(
            AuditRecordType::VersionReferenceChanged,
            ['name' => $package->getName(), 'version' => $version->getVersion(), 'source_from' => $oldSourceReference, 'source_to' => $version->getSource()['reference'] ?? null, 'dist_from' => $oldDistReference, 'dist_to' => $version->getDist()['reference'] ?? null, 'metadata' => $metadata],
            vendor: $package->getVendor(),
            packageId: $package->getId()
        );
    }

    public static function maintainerAdded(Package $package, User $maintainer, ?User $actor): self
    {
        return new self(AuditRecordType::MaintainerAdded, ['name' => $package->getName(), 'maintainer' => self::getUserData($maintainer), 'actor' => self::getUserData($actor)], $actor?->getId(), $package->getVendor(), $package->getId(), $maintainer->getId());
    }

    public static function maintainerRemoved(Package $package, User $maintainer, ?User $actor): self
    {
        return new self(AuditRecordType::MaintainerRemoved, ['name' => $package->getName(), 'maintainer' => self::getUserData($maintainer), 'actor' => self::getUserData($actor)], $actor?->getId(), $package->getVendor(), $package->getId(), $maintainer->getId());
    }

    public static function packageAbandoned(Package $package, ?User $actor, ?string $replacementPackage, ?AbandonmentReason $reason = null): self
    {
        return new self(AuditRecordType::PackageAbandoned, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'replacement_package' => $replacementPackage, 'reason' => $reason?->value, 'actor' => self::getUserData($actor, 'automation')], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    public static function packageUnabandoned(Package $package, ?User $actor): self
    {
        return new self(AuditRecordType::PackageUnabandoned, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($actor, 'automation')], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    public static function userCreated(User $user, UserRegistrationMethod $method): self
    {
        return new self(
            AuditRecordType::UserCreated,
            [
                'username' => $user->getUsernameCanonical(),
                'method' => $method->value,
                'actor' => 'unknown',
            ],
            userId: $user->getId(),
        );
    }

    public static function twoFactorAuthenticationActivated(User $user): self
    {
        return new self(
            AuditRecordType::TwoFaAuthenticationActivated,
            [
                'username' => $user->getUsernameCanonical(),
                'actor' => self::getUserData($user),
            ],
            actorId: $user->getId(),
            userId: $user->getId(),
        );
    }

    public static function twoFactorAuthenticationDeactivated(User $user, string $reason): self
    {
        return new self(
            AuditRecordType::TwoFaAuthenticationDeactivated,
            [
                'username' => $user->getUsernameCanonical(),
                'actor' => self::getUserData($user),
                'reason' => $reason,
            ],
            actorId: $user->getId(),
        );
    }

    public static function passwordReset(User $user): self
    {
        return new self(type: AuditRecordType::PasswordReset, attributes: ['user' => self::getUserData($user), 'actor' => self::getUserData($user)], actorId: $user->getId(), userId: $user->getId());
    }

    public static function passwordChanged(User $user): self
    {
        return new self(AuditRecordType::PasswordChanged, ['user' => self::getUserData($user), 'actor' => self::getUserData($user)], actorId: $user->getId(), userId: $user->getId());
    }

    public static function passwordResetRequested(User $user): self
    {
        return new self(AuditRecordType::PasswordResetRequested, ['user' => self::getUserData($user), 'actor' => self::getUserData($user)], actorId: $user->getId(), userId: $user->getId());
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
