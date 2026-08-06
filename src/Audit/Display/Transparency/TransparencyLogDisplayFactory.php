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

namespace App\Audit\Display\Transparency;

use App\Audit\Display\ActorDisplay;
use App\Audit\TransparencyLogType;
use App\Entity\PackageTransparencyLog;

/**
 * Builds display objects for the public package transparency log from already-scrubbed entries.
 *
 * Deliberately separate from {@see \App\Audit\Display\AuditLogDisplayFactory}: it reads the scrubbed
 * `package_transparency_log` attributes (no IP, no emails, no internal moderation notes) and needs no
 * Security service, because there is nothing privileged left to gate.
 */
class TransparencyLogDisplayFactory
{
    /**
     * @param iterable<PackageTransparencyLog> $entries
     *
     * @return list<TransparencyLogDisplayInterface>
     */
    public function build(iterable $entries): array
    {
        $displays = [];
        foreach ($entries as $entry) {
            $displays[] = $this->buildSingle($entry);
        }

        return $displays;
    }

    public function buildSingle(PackageTransparencyLog $entry): TransparencyLogDisplayInterface
    {
        $attributes = $entry->attributes;

        return match ($entry->type) {
            TransparencyLogType::MaintainerAdded, TransparencyLogType::MaintainerRemoved => new MaintainerChangeDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $this->buildActor($attributes['user'] ?? null),
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::PackageTransferred => new PackageTransferredDisplay(
                $entry->datetime,
                $attributes['name'],
                $attributes['previous_maintainers'] ?? [],
                $attributes['current_maintainers'] ?? [],
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::PackageCreated, TransparencyLogType::PackageUnabandoned, TransparencyLogType::PackageUnfrozen => new PackageRepositoryDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['repository'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::CanonicalUrlChanged => new CanonicalUrlChangedDisplay(
                $entry->datetime,
                $attributes['name'],
                $attributes['repository_from'] ?? null,
                $attributes['repository_to'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::PackageAbandoned => new PackageAbandonedDisplay(
                $entry->datetime,
                $attributes['name'],
                $attributes['repository'] ?? null,
                $attributes['replacement_package'] ?? null,
                $attributes['reason'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::PackageFrozen => new PackageFrozenDisplay(
                $entry->datetime,
                $attributes['name'],
                $attributes['repository'] ?? null,
                $attributes['reason'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::PackageDeleted => new PackageDeletedDisplay(
                $entry->datetime,
                $attributes['name'],
                $attributes['repository'] ?? null,
                $attributes['reason'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::VersionCreated, TransparencyLogType::VersionDeleted => new VersionDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['name'],
                $attributes['version'],
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::VersionSoftDeleted => new VersionSoftDeletedDisplay(
                $entry->datetime,
                $attributes['name'],
                $attributes['version'],
                $attributes['reason'],
                $attributes['reasonText'] ?? null,
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::VersionRecovered => new VersionRecoveredDisplay(
                $entry->datetime,
                $attributes['name'],
                $attributes['version'],
                $attributes['previousReason'],
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::VersionReferenceChangeBlocked => new VersionReferenceChangeBlockedDisplay(
                $entry->datetime,
                $attributes['name'],
                $attributes['version'],
                $attributes['ref_from'] ?? null,
                $attributes['ref_to'],
                $this->buildActor($attributes['actor'] ?? null),
            ),
            TransparencyLogType::TwoFaActivated, TransparencyLogType::TwoFaDeactivated,
            TransparencyLogType::PasswordReset, TransparencyLogType::PasswordChanged,
            TransparencyLogType::EmailChanged, TransparencyLogType::GitHubLinkedWithUser, TransparencyLogType::GitHubDisconnectedFromUser => new MaintainerAccountEventDisplay(
                $entry->type,
                $entry->datetime,
                $attributes['user']['username'],
                $this->buildActor($attributes['actor'] ?? null),
            ),
        };
    }

    /**
     * @param array{id: int|null, username: string}|string|null $actor
     */
    private function buildActor(array|string|null $actor): ActorDisplay
    {
        if ($actor === null) {
            return new ActorDisplay(null, 'unknown');
        }

        if (\is_string($actor)) {
            return new ActorDisplay(null, $actor);
        }

        return new ActorDisplay($actor['id'], $actor['username']);
    }
}
