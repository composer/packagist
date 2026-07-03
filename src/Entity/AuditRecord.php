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
use App\Audit\AuditLogSearchType;
use App\Audit\AuditRecordType;
use App\Audit\Display\OrganizationDisplay;
use App\Audit\UserRegistrationMethod;
use App\Audit\VersionDeletionReason;
use Composer\Pcre\Preg;
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
class AuditRecord
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid')]
    public readonly Ulid $id;

    #[ORM\Column]
    public readonly \DateTimeImmutable $datetime;

    #[ORM\Column(nullable: true, type: 'ipaddress')]
    // @phpstan-ignore property.uninitializedReadonly
    public readonly ?string $ip;

    private function __construct(
        #[ORM\Column]
        public readonly AuditRecordType $type,

        /**
         * Special attribute names have special meaning:
         *
         *  - name = package name
         *  - user.username = user name
         *  - organization.org_slug = org slug
         *  - actor.username = actor name
         *
         * @var array<string, mixed>
         */
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
        #[ORM\Column(type: 'ulid', nullable: true)]
        public readonly ?Ulid $organizationId = null,
    ) {
        $this->id = new Ulid();
        $this->datetime = new \DateTimeImmutable();
    }

    public function setIp(?string $ip): void
    {
        // @phpstan-ignore property.readOnlyAssignNotInConstructor
        $this->ip = $ip;
    }

    /**
     * The names this record references, keyed by role, for the audit_log_search index.
     *
     * Derived generically from the JSON attributes by key presence, so it stays correct as new
     * record types reuse the same keys (the shapes mirror what AuditLogDisplayFactory reads).
     * Names are lowercased to match the case-insensitive lookup the transparency-log filters do
     * (usernames are canonically lowercased anyway). String actor/user sentinels such as
     * 'automation'/'self'/'anonymous' are not indexed — only real usernames and package names are.
     *
     * @return list<array{type: string, name: string}>
     */
    public function getSearchTerms(): array
    {
        $terms = [];
        $add = static function (AuditLogSearchType $type, mixed $name) use (&$terms): void {
            if (!\is_string($name) || $name === '') {
                return;
            }
            $lower = mb_strtolower($name);
            // keyed to de-duplicate (e.g. user.username === username_to on a rename)
            $terms[$type->value."\0".$lower] = ['type' => $type->value, 'name' => $lower];
        };

        $add(AuditLogSearchType::Package, $this->attributes['name'] ?? null);

        $userData = $this->attributes['user'] ?? null;
        if (\is_array($userData)) {
            $add(AuditLogSearchType::User, $userData['username'] ?? null);
        }

        // package ownership transfers store maintainer snapshots as arrays of {id, username}
        foreach (['current_maintainers', 'previous_maintainers'] as $key) {
            $maintainers = $this->attributes[$key] ?? null;
            if (\is_array($maintainers)) {
                foreach ($maintainers as $maintainer) {
                    if (\is_array($maintainer)) {
                        $add(AuditLogSearchType::User, $maintainer['username'] ?? null);
                    }
                }
            }
        }

        // index both sides of a username change so searching either handle surfaces the rename
        $add(AuditLogSearchType::User, $this->attributes['username_from'] ?? null);
        $add(AuditLogSearchType::User, $this->attributes['username_to'] ?? null);

        $actorData = $this->attributes['actor'] ?? null;
        if (\is_array($actorData)) {
            $add(AuditLogSearchType::Actor, $actorData['username'] ?? null);
        }

        // organizations are searchable by slug only (never the display name); see OrganizationDisplay
        $orgData = $this->attributes['organization'] ?? null;
        if (\is_array($orgData)) {
            $add(AuditLogSearchType::Organization, $orgData['org_slug'] ?? null);
        }

        // index both sides of a slug change so searching either slug surfaces the rename
        $add(AuditLogSearchType::Organization, $this->attributes['org_slug_from'] ?? null);
        $add(AuditLogSearchType::Organization, $this->attributes['org_slug_to'] ?? null);

        return array_values($terms);
    }

    public static function packageCreated(Package $package, ?User $actor): self
    {
        return new self(
            AuditRecordType::PackageCreated,
            ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($actor)],
            $actor?->getId(),
            $package->getVendor(),
            $package->getId()
        );
    }

    public static function packageDeleted(Package $package, ?User $actor, ?string $reason = null, ?string $internalReason = null): self
    {
        return new self(
            AuditRecordType::PackageDeleted,
            ['name' => $package->getName(), 'repository' => $package->getRepository(), 'reason' => $reason, 'internalReason' => $internalReason, 'actor' => self::getUserData($actor, 'automation')],
            $actor?->getId(),
            $package->getVendor(),
            $package->getId()
        );
    }

    public static function organizationCreated(Ulid $organizationId, string $slug, string $displayName, ?User $actor): self
    {
        return new self(
            AuditRecordType::OrganizationCreated,
            [
                'organization' => new OrganizationDisplay((string) $organizationId, $slug, $displayName)->toRecord(),
                'actor' => self::getUserData($actor),
            ],
            $actor?->getId(),
            organizationId: $organizationId,
        );
    }

    public static function canonicalUrlChange(Package $package, ?User $actor, string $oldRepository): self
    {
        return new self(
            AuditRecordType::CanonicalUrlChanged,
            ['name' => $package->getName(), 'repository_from' => $oldRepository, 'repository_to' => $package->getRepository(), 'actor' => self::getUserData($actor)],
            $actor?->getId(),
            $package->getVendor(),
            $package->getId()
        );
    }

    /**
     * @param User[] $previousMaintainers
     * @param User[] $currentMaintainers
     */
    public static function packageTransferred(Package $package, ?User $actor, array $previousMaintainers, array $currentMaintainers): self
    {
        $previous = array_values(array_map(self::getUserData(...), $previousMaintainers));
        $current = array_values(array_map(self::getUserData(...), $currentMaintainers));

        return new self(
            AuditRecordType::PackageTransferred,
            ['name' => $package->getName(), 'actor' => self::getUserData($actor, 'admin'), 'previous_maintainers' => $previous, 'current_maintainers' => $current],
            $actor?->getId(),
            $package->getVendor(),
            $package->getId()
        );
    }

    /**
     * @param VersionArray $metadata
     */
    public static function versionCreated(Version $version, array $metadata, ?User $actor): self
    {
        $package = $version->getPackage();

        return new self(
            AuditRecordType::VersionCreated,
            ['name' => $package->getName(), 'version' => $version->getVersion(), 'actor' => self::getUserData($actor, 'automation'), 'metadata' => $metadata],
            $actor?->getId(),
            $package->getVendor(),
            $package->getId()
        );
    }

    public static function versionDeleted(Version $version, ?User $actor): self
    {
        $package = $version->getPackage();

        return new self(
            AuditRecordType::VersionDeleted,
            ['name' => $package->getName(), 'version' => $version->getVersion(), 'actor' => self::getUserData($actor, 'automation')],
            $actor?->getId(),
            $package->getVendor(),
            $package->getId()
        );
    }

    public static function versionSoftDeleted(Version $version, VersionDeletionReason $reason, ?string $reasonText, ?string $internalReasonText, ?User $actor): self
    {
        $package = $version->getPackage();

        return new self(
            AuditRecordType::VersionSoftDeleted,
            ['name' => $package->getName(), 'version' => $version->getVersion(), 'reason' => $reason->value, 'reasonText' => $reasonText, 'internalReasonText' => $internalReasonText, 'actor' => self::getUserData($actor, 'automation')],
            $actor?->getId(),
            $package->getVendor(),
            $package->getId()
        );
    }

    public static function versionRecovered(Version $version, VersionDeletionReason $previousReason, ?User $actor): self
    {
        $package = $version->getPackage();

        return new self(
            AuditRecordType::VersionRecovered,
            ['name' => $package->getName(), 'version' => $version->getVersion(), 'previousReason' => $previousReason->value, 'actor' => self::getUserData($actor, 'automation')],
            $actor?->getId(),
            $package->getVendor(),
            $package->getId()
        );
    }

    public static function versionReferenceChangeBlocked(Package $package, string $prettyVersion, ?string $oldRef, string $newRef): self
    {
        return new self(
            AuditRecordType::VersionReferenceChangeBlocked,
            ['name' => $package->getName(), 'version' => $prettyVersion, 'ref_from' => $oldRef, 'ref_to' => $newRef],
            vendor: $package->getVendor(),
            packageId: $package->getId()
        );
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
        return new self(AuditRecordType::MaintainerAdded, ['name' => $package->getName(), 'user' => self::getUserData($maintainer), 'actor' => self::getUserData($actor)], $actor?->getId(), $package->getVendor(), $package->getId(), $maintainer->getId());
    }

    public static function maintainerRemoved(Package $package, User $maintainer, ?User $actor): self
    {
        return new self(AuditRecordType::MaintainerRemoved, ['name' => $package->getName(), 'user' => self::getUserData($maintainer), 'actor' => self::getUserData($actor)], $actor?->getId(), $package->getVendor(), $package->getId(), $maintainer->getId());
    }

    public static function packageAbandoned(Package $package, ?User $actor, ?string $replacementPackage, ?AbandonmentReason $reason = null): self
    {
        return new self(AuditRecordType::PackageAbandoned, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'replacement_package' => $replacementPackage, 'reason' => $reason?->value, 'actor' => self::getUserData($actor, 'automation')], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    public static function packageUnabandoned(Package $package, ?User $actor): self
    {
        return new self(AuditRecordType::PackageUnabandoned, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($actor, 'automation')], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    public static function packageFrozen(Package $package, ?User $actor, PackageFreezeReason $reason): self
    {
        return new self(AuditRecordType::PackageFrozen, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'reason' => $reason->value, 'actor' => self::getUserData($actor, 'automation')], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    public static function packageUnfrozen(Package $package, ?User $actor): self
    {
        return new self(AuditRecordType::PackageUnfrozen, ['name' => $package->getName(), 'repository' => $package->getRepository(), 'actor' => self::getUserData($actor, 'automation')], $actor?->getId(), $package->getVendor(), $package->getId());
    }

    public static function userCreated(User $user, UserRegistrationMethod $method): self
    {
        return new self(
            AuditRecordType::UserCreated,
            [
                'user' => self::getUserData($user),
                'method' => $method->value,
                'actor' => 'self',
            ],
            userId: $user->getId(),
        );
    }

    public static function twoFactorAuthenticationActivated(User $user, User $actor): self
    {
        return new self(
            AuditRecordType::TwoFaAuthenticationActivated,
            [
                'user' => self::getUserData($user),
                'actor' => self::getUserData($actor),
            ],
            actorId: $actor->getId(),
            userId: $user->getId(),
        );
    }

    public static function twoFactorAuthenticationDeactivated(User $user, User $actor, string $reason): self
    {
        return new self(
            AuditRecordType::TwoFaAuthenticationDeactivated,
            [
                'user' => self::getUserData($user),
                'actor' => self::getUserData($actor),
                'reason' => $reason,
            ],
            actorId: $actor->getId(),
        );
    }

    public static function passwordReset(User $user, User $actor): self
    {
        return new self(type: AuditRecordType::PasswordReset, attributes: ['user' => self::getUserData($user), 'actor' => self::getUserData($actor)], actorId: $user->getId(), userId: $user->getId());
    }

    public static function passwordChanged(User $user, User $actor): self
    {
        return new self(AuditRecordType::PasswordChanged, ['user' => self::getUserData($user), 'actor' => self::getUserData($actor)], actorId: $actor->getId(), userId: $user->getId());
    }

    public static function passwordResetRequested(User $user): self
    {
        return new self(AuditRecordType::PasswordResetRequested, ['user' => self::getUserData($user), 'actor' => 'anonymous'], userId: $user->getId());
    }

    public static function userDeleted(User $user, ?User $actor): self
    {
        return new self(
            AuditRecordType::UserDeleted,
            [
                'user' => self::getUserData($user),
                'actor' => self::getUserData($actor, 'automation'),
            ],
            actorId: $actor?->getId(),
            userId: $user->getId(),
        );
    }

    public static function userVerified(User $user, User $actor, string $email): self
    {
        return new self(AuditRecordType::UserVerified, ['user' => self::getUserdata($user), 'email' => $email, 'actor' => self::getUserData($actor)], userId: $user->getId(), actorId: $actor->getId());
    }

    public static function usernameChanged(User $user, User $actor, string $oldUsername): self
    {
        return new self(
            AuditRecordType::UsernameChanged,
            [
                'username_from' => $oldUsername,
                'username_to' => $user->getUsernameCanonical(),
                'user' => self::getUserData($user),
                'actor' => self::getUserData($actor),
            ],
            actorId: $actor->getId(),
            userId: $user->getId(),
        );
    }

    public static function emailChanged(User $user, User $actor, string $oldEmail): self
    {
        return new self(
            AuditRecordType::EmailChanged,
            [
                'email_from' => $oldEmail,
                'email_to' => $user->getEmail(),
                'user' => self::getUserData($user),
                'actor' => self::getUserData($actor),
            ],
            actorId: $actor->getId(),
            userId: $user->getId(),
        );
    }

    public static function gitHubLinkedWithUser(User $user, User $actor, string $githubUsername, int $githubId): self
    {
        return new self(
            AuditRecordType::GitHubLinkedWithUser,
            [
                'user' => self::getUserData($user),
                'github_username' => $githubUsername,
                'github_id' => $githubId,
                'actor' => self::getUserData($actor),
            ],
            actorId: $actor->getId(),
            userId: $user->getId(),
        );
    }

    public static function gitHubDisconnectedFromUser(User $user, User $actor): self
    {
        return new self(
            AuditRecordType::GitHubDisconnectedFromUser,
            [
                'user' => self::getUserData($user),
                'actor' => self::getUserData($actor),
            ],
            actorId: $actor->getId(),
            userId: $user->getId(),
        );
    }

    public static function filterListEntryAdded(FilterListEntry $entry, ?User $actor): self
    {
        return new self(
            AuditRecordType::FilterListEntryAdded,
            [
                'name' => $entry->getPackageName(),
                'entry' => self::getFilterListEntryData($entry),
                'actor' => self::getUserData($actor, 'automation'),
            ],
            vendor: self::getVendorFromPackage($entry->getPackageName()),
            actorId: $actor?->getId(),
        );
    }

    public static function filterListEntryDeleted(FilterListEntry $entry, ?User $actor): self
    {
        return new self(
            AuditRecordType::FilterListEntryDeleted,
            [
                'name' => $entry->getPackageName(),
                'entry' => self::getFilterListEntryData($entry),
                'actor' => self::getUserData($actor, 'automation'),
            ],
            vendor: self::getVendorFromPackage($entry->getPackageName()),
            actorId: $actor?->getId(),
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

    /**
     * @return array{package_name: string, version: string, list: string}
     */
    private static function getFilterListEntryData(FilterListEntry $entry): array
    {
        return [
            'package_name' => $entry->getPackageName(),
            'version' => $entry->getVersion(),
            'list' => $entry->getList()->value,
            'reason' => $entry->getReason(),
            'source' => $entry->getSource()->value,
        ];
    }

    private static function getVendorFromPackage(string $packageName): string
    {
        return Preg::replace('{/.*$}', '', $packageName);
    }
}
