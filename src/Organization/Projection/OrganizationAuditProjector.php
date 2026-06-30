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

namespace App\Organization\Projection;

use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\UserRepository;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Event\OrganizationNameChanged;
use App\Organization\Domain\Event\OrganizationSlugChanged;
use App\Organization\EventStore\RecordedEvent;

/**
 * Projects organization events into the public transparency log (`audit_log`).
 */
final readonly class OrganizationAuditProjector implements Projector
{
    public function __construct(
        private AuditRecordRepository $auditRecords,
        private UserRepository $users,
    ) {
    }

    public function project(RecordedEvent $recorded): void
    {
        $event = $recorded->event;
        $actor = $recorded->actor->userId !== null ? $this->users->find($recorded->actor->userId) : null;

        $this->auditRecords->insert(
            match (true) {
                $event instanceof OrganizationCreated => AuditRecord::organizationCreated($event->organizationId, $event->slug, $event->displayName, $actor),
                $event instanceof OrganizationNameChanged => AuditRecord::organizationRenamed($event->organizationId, $event->displayName, $event->previousDisplayName, $actor),
                $event instanceof OrganizationSlugChanged => AuditRecord::organizationSlugChanged($event->organizationId, $event->slug, $event->previousSlug, $actor),
                default => throw new \LogicException('Unhandled event: ' . $event->eventType()->value),
            }
        );
    }
}
