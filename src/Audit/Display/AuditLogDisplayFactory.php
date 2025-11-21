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

namespace App\Audit\Display;

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;

class AuditLogDisplayFactory
{
    /**
     * @param iterable<AuditRecord> $auditRecords
     * @return array<AuditLogDisplayInterface>
     */
    public function build(iterable $auditRecords): array
    {
        $displays = [];
        foreach ($auditRecords as $record) {
            $displays[] = $this->buildSingle($record);
        }

        return $displays;
    }

    public function buildSingle(AuditRecord $record): AuditLogDisplayInterface
    {
        return match ($record->type) {
            AuditRecordType::MaintainerAdded => new MaintainerAddedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $this->buildActor($record->attributes['maintainer']),
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::MaintainerRemoved => new MaintainerRemovedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $this->buildActor($record->attributes['maintainer']),
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::PackageTransferred => new PackageTransferredDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['previous_maintainers'],
                $record->attributes['current_maintainers'],
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::PackageCreated => new PackageCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::PackageDeleted => new PackageDeletedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::CanonicalUrlChanged => new CanonicalUrlChangedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository_from'],
                $record->attributes['repository_to'],
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::VersionCreated => new VersionCreatedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['source'] ?? null,
                $record->attributes['dist'] ?? null,
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::VersionDeleted => new VersionDeletedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::VersionReferenceChanged => new VersionReferenceChangedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['version'],
                $record->attributes['source_from'] ?? null,
                $record->attributes['source_to'] ?? null,
                $record->attributes['dist_from'] ?? null,
                $record->attributes['dist_to'] ?? null,
                $this->buildActor($record->attributes['actor'] ?? null),
            ),
            default => throw new \LogicException(sprintf('Unsupported audit record type: %s', $record->type->value)),
        };
    }

    /**
     * @param array{id: int, username: string}|string|null $actor
     */
    private function buildActor(array|string|null $actor): ActorDisplay
    {
        if ($actor === null) {
            return new ActorDisplay(null, 'unknown');
        }

        if (is_string($actor)) {
            return new ActorDisplay(null, $actor);
        }

        return new ActorDisplay($actor['id'], $actor['username']);
    }
}
