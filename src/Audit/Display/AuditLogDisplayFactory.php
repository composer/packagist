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
use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\Audit\Display\TwoFaActivatedDisplay;
use App\Audit\Display\TwoFaDeactivatedDisplay;

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
                $record->attributes['metadata']['source']['reference'] ?? null,
                $record->attributes['metadata']['dist']['reference']  ?? null,
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::PackageAbandoned => new PackageAbandonedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
                $record->attributes['replacement_package'] ?? null,
                $record->attributes['reason'] ?? null,
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::PackageUnabandoned => new PackageUnabandonedDisplay(
                $record->datetime,
                $record->attributes['name'],
                $record->attributes['repository'],
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
            AuditRecordType::UserCreated => new UserCreatedDisplay(
                $record->datetime,
                $record->attributes['username'],
                UserRegistrationMethod::from($record->attributes['method']),
                $this->buildActor(null),
            ),
            AuditRecordType::TwoFaAuthenticationActivated => new GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['username'],
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::TwoFaAuthenticationDeactivated => new TwoFaDeactivatedDisplay(
                $record->datetime,
                $record->attributes['username'],
                $record->attributes['reason'],
                $this->buildActor($record->attributes['actor']),
            ),
            AuditRecordType::PasswordResetRequested, AuditRecordType::PasswordReset, AuditRecordType::PasswordChanged => new GenericUserDisplay(
                $record->type,
                $record->datetime,
                $record->attributes['user']['username'] ?? 'unknown',
                $this->buildActor(null),
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
