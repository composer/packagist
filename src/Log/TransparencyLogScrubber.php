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

namespace App\Log;

use App\Log\TransparencyLogAttribute as Attribute;

/**
 * The log is immutable, so publishing is an allow-list per type ({@see self::publishedAttributesFor()}):
 * a new audit record attribute stays out until it is added there.
 */
class TransparencyLogScrubber
{
    /**
     * Scalar keys published out of a version metadata blob.
     */
    private const PUBLISHED_METADATA_KEYS = [
        'version_normalized',
    ];

    /**
     * Sections of a version metadata blob, and the keys published out of each: what the version
     * resolved to at publication time.
     *
     * Without the reference a leaf says 1.2.3 was published but nothing about what it contained, so
     * a delete-and-recreate under the same version leaves no trace. It attests what the upstream host
     * told us at crawl time, not bytes we verified: `dist.shasum` is whatever the VCS driver reported
     * ({@see \App\Package\Updater}).
     */
    private const PUBLISHED_METADATA_SECTIONS = [
        'source' => ['type', 'url', 'reference'],
        'dist' => ['type', 'url', 'reference', 'shasum'],
    ];

    /**
     * Drop keys not on the allow-list. Throw exception when an allowed key has the wrong shape, so the change is
     * noticed: the projector will keep the record queued.
     *
     * @param array<string, mixed> $attributes
     *
     * @return array<string, mixed>
     *
     * @throws \UnexpectedValueException
     */
    public function scrub(AuditLogEventType $type, array $attributes): array
    {
        $transparencyLogType = TransparencyLogEventType::fromAuditLogEventType($type);
        if ($transparencyLogType === null) {
            return [];
        }

        $publishedAttributes = $this->publishedAttributesFor($transparencyLogType);

        $published = [];
        foreach ($attributes as $key => $value) {
            $attribute = $publishedAttributes[$key] ?? null;
            if ($attribute === null) {
                continue;
            }

            if ($value === null) {
                $published[$key] = null;
                continue;
            }

            $reduced = match ($attribute) {
                Attribute::Scalar => \is_scalar($value) ? $value : null,
                Attribute::User => $this->reduceUser($value),
                Attribute::UserList => $this->reduceUserList($value),
                Attribute::VersionMetadata => $this->reduceVersionMetadata($value),
            };
            if ($reduced !== null) {
                $published[$key] = $reduced;
                continue;
            }

            // publisher content: nothing publishable in it is normal
            if ($attribute === Attribute::VersionMetadata) {
                continue;
            }

            // no value in the message, it is logged
            throw new \UnexpectedValueException(\sprintf('The "%s" attribute of a %s audit record does not have the %s shape, so it cannot be published', $key, $type->value, $attribute->name));
        }

        return $published;
    }

    /**
     * No default: each new type must be added here.
     *
     * Not projected: `internalReason`, `internalReasonText` (admin-only), `email_from`, `email_to` (PII),
     * 2FA `reason` (undecided).
     *
     * @return array<string, Attribute>
     */
    private function publishedAttributesFor(TransparencyLogEventType $type): array
    {
        return match ($type) {
            TransparencyLogEventType::MaintainerAdded, TransparencyLogEventType::MaintainerRemoved => [
                'name' => Attribute::Scalar,
                'user' => Attribute::User,
                'actor' => Attribute::User,
            ],
            TransparencyLogEventType::PackageTransferred => [
                'name' => Attribute::Scalar,
                'actor' => Attribute::User,
                'previous_maintainers' => Attribute::UserList,
                'current_maintainers' => Attribute::UserList,
            ],
            TransparencyLogEventType::PackageCreated => [
                'name' => Attribute::Scalar,
                'repository' => Attribute::Scalar,
                'actor' => Attribute::User,
                // only on moderator submissions
                'user' => Attribute::User,
            ],
            TransparencyLogEventType::CanonicalUrlChanged => [
                'name' => Attribute::Scalar,
                'repository_from' => Attribute::Scalar,
                'repository_to' => Attribute::Scalar,
                'actor' => Attribute::User,
            ],
            TransparencyLogEventType::PackageAbandoned => [
                'name' => Attribute::Scalar,
                'repository' => Attribute::Scalar,
                'replacement_package' => Attribute::Scalar,
                'reason' => Attribute::Scalar,
                'actor' => Attribute::User,
            ],
            TransparencyLogEventType::PackageUnabandoned, TransparencyLogEventType::PackageUnfrozen => [
                'name' => Attribute::Scalar,
                'repository' => Attribute::Scalar,
                'actor' => Attribute::User,
            ],
            TransparencyLogEventType::PackageFrozen, TransparencyLogEventType::PackageDeleted => [
                'name' => Attribute::Scalar,
                'repository' => Attribute::Scalar,
                'reason' => Attribute::Scalar,
                'actor' => Attribute::User,
            ],
            TransparencyLogEventType::VersionCreated => [
                'name' => Attribute::Scalar,
                'version' => Attribute::Scalar,
                'actor' => Attribute::User,
                'metadata' => Attribute::VersionMetadata,
            ],
            TransparencyLogEventType::VersionReferenceChangeBlocked => [
                'name' => Attribute::Scalar,
                'version' => Attribute::Scalar,
                'ref_from' => Attribute::Scalar,
                'ref_to' => Attribute::Scalar,
            ],
            TransparencyLogEventType::VersionDeleted => [
                'name' => Attribute::Scalar,
                'version' => Attribute::Scalar,
                'actor' => Attribute::User,
            ],
            TransparencyLogEventType::VersionSoftDeleted => [
                'name' => Attribute::Scalar,
                'version' => Attribute::Scalar,
                'reason' => Attribute::Scalar,
                'reasonText' => Attribute::Scalar,
                'actor' => Attribute::User,
            ],
            TransparencyLogEventType::VersionRecovered => [
                'name' => Attribute::Scalar,
                'version' => Attribute::Scalar,
                'previousReason' => Attribute::Scalar,
                'actor' => Attribute::User,
            ],
            TransparencyLogEventType::TwoFactorAuthenticationActivated, TransparencyLogEventType::TwoFactorAuthenticationDeactivated,
            TransparencyLogEventType::PasswordReset, TransparencyLogEventType::PasswordChanged,
            TransparencyLogEventType::EmailChanged, TransparencyLogEventType::GitHubDisconnectedFromUser => [
                'user' => Attribute::User,
                'actor' => Attribute::User,
            ],
            TransparencyLogEventType::GitHubLinkedWithUser => [
                'user' => Attribute::User,
                'github_username' => Attribute::Scalar,
                'github_id' => Attribute::Scalar,
                'actor' => Attribute::User,
            ],
        };
    }

    /**
     * Keeps `id` and `username`, or a fallback string such as 'automation'. Null when invalid.
     *
     * @return array{id?: int|null, username: string}|string|null
     */
    private function reduceUser(mixed $value): array|string|null
    {
        if (\is_string($value)) {
            return $value;
        }

        if (!\is_array($value) || !\is_string($value['username'] ?? null)) {
            return null;
        }

        $user = [];
        if (\array_key_exists('id', $value)) {
            if (!\is_int($value['id']) && $value['id'] !== null) {
                return null;
            }
            $user['id'] = $value['id'];
        }
        $user['username'] = $value['username'];

        return $user;
    }

    /**
     * Null when any item is invalid.
     *
     * @return list<array{id?: int|null, username: string}|string>|null
     */
    private function reduceUserList(mixed $value): ?array
    {
        if (!\is_array($value) || !array_is_list($value)) {
            return null;
        }

        $users = [];
        foreach ($value as $item) {
            $user = $this->reduceUser($item);
            if ($user === null) {
                return null;
            }
            $users[] = $user;
        }

        return $users;
    }

    /**
     * Reduces the blob to {@see self::PUBLISHED_METADATA_KEYS} and
     * {@see self::PUBLISHED_METADATA_SECTIONS}, or null when nothing is left.
     *
     * Only non-empty strings are kept, so nothing nested can slip through a published section.
     *
     * @return array<string, string|array<string, string>>|null
     */
    private function reduceVersionMetadata(mixed $metadata): ?array
    {
        if (!\is_array($metadata)) {
            return null;
        }

        $published = [];
        foreach (self::PUBLISHED_METADATA_KEYS as $key) {
            $value = $metadata[$key] ?? null;
            if (\is_string($value) && $value !== '') {
                $published[$key] = $value;
            }
        }

        foreach (self::PUBLISHED_METADATA_SECTIONS as $section => $keys) {
            $sectionValues = [];
            foreach ($keys as $key) {
                $value = \is_array($metadata[$section] ?? null) ? ($metadata[$section][$key] ?? null) : null;
                if (\is_string($value) && $value !== '') {
                    $sectionValues[$key] = $value;
                }
            }

            if ($sectionValues !== []) {
                $published[$section] = $sectionValues;
            }
        }

        return $published === [] ? null : $published;
    }
}
