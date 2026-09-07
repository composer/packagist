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

namespace App\Log\Display;

use App\Entity\PackageTransparencyLog;
use App\Log\TransparencyLogEventType;

/**
 * Builds display objects for the public package transparency log from already-scrubbed entries.
 *
 * Deliberately separate from {@see \App\Log\Display\AuditLogDisplayFactory}: it reads the scrubbed
 * `package_transparency_log` attributes (no IP, no emails, no internal moderation notes) and needs no
 * Security service, because there is nothing privileged left to gate.
 */
class TransparencyLogDisplayFactory extends AbstractLogDisplayFactory
{
    /**
     * @param iterable<PackageTransparencyLog> $entries
     *
     * @return list<LogDisplayInterface>
     */
    public function build(iterable $entries): array
    {
        $displays = [];
        foreach ($entries as $entry) {
            $displays[] = $this->buildSingle($entry);
        }

        return $displays;
    }

    public function buildSingle(PackageTransparencyLog $entry): LogDisplayInterface
    {
        $attributes = $entry->attributes;

        return match ($entry->type) {
            TransparencyLogEventType::MaintainerAdded, TransparencyLogEventType::MaintainerRemoved => new Event\MaintainerChangeDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $this->buildActor($attributes['user'] ?? null),
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::PackageTransferred => new Event\PackageTransferredDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['previous_maintainers'] ?? [],
                $attributes['current_maintainers'] ?? [],
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::PackageCreated, TransparencyLogEventType::PackageUnabandoned, TransparencyLogEventType::PackageUnfrozen => new Event\PackageWithRepositoryDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['repository'],
                null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::CanonicalUrlChanged => new Event\CanonicalUrlChangedDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['repository_from'] ?? null,
                $attributes['repository_to'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::PackageAbandoned => new Event\PackageAbandonedDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['repository'] ?? null,
                $attributes['replacement_package'] ?? null,
                $attributes['reason'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::PackageFrozen => new Event\PackageFrozenDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['repository'] ?? null,
                $attributes['reason'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::PackageDeleted => new Event\PackageDeletedDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['repository'] ?? null,
                $attributes['reason'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::VersionCreated, TransparencyLogEventType::VersionDeleted => new Event\VersionDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['version'],
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::VersionSoftDeleted => new Event\VersionSoftDeletedDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['version'],
                $attributes['reason'],
                $attributes['reasonText'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::VersionRecovered => new Event\VersionRecoveredDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['version'],
                $attributes['previousReason'],
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::VersionReferenceChangeBlocked => new Event\VersionReferenceChangeBlockedDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['version'],
                $attributes['ref_from'] ?? null,
                $attributes['ref_to'],
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogEventType::TwoFactorAuthenticationActivated, TransparencyLogEventType::TwoFactorAuthenticationDeactivated,
            TransparencyLogEventType::PasswordReset, TransparencyLogEventType::PasswordChanged,
            TransparencyLogEventType::EmailChanged, TransparencyLogEventType::GitHubLinkedWithUser, TransparencyLogEventType::GitHubDisconnectedFromUser => new Event\MaintainerAccountEventDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['user']['username'],
                $entry->packageName,
                $this->buildActor($attributes['actor'] ?? null),
            ),
        };
    }
}
