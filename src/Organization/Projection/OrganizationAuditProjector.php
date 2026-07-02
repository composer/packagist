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
use App\Entity\Organization;
use App\Entity\OrganizationRepository;
use App\Entity\UserRepository;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Event\OrganizationNameChanged;
use App\Organization\Domain\Event\OrganizationSlugChanged;
use App\Organization\EventStore\RecordedEvent;
use Symfony\Component\Uid\Ulid;

/**
 * Projects organization events into the public transparency log (`audit_log`).
 */
final readonly class OrganizationAuditProjector implements Projector
{
    public function __construct(
        private AuditRecordRepository $auditRecords,
        private UserRepository $users,
        private OrganizationRepository $organizations,
    ) {
    }

    public function project(RecordedEvent $recorded): void
    {
        $event = $recorded->event;
        $actor = $recorded->actor->userId !== null ? $this->users->find($recorded->actor->userId) : null;

        $this->auditRecords->insert(
            match (true) {
                $event instanceof OrganizationCreated => AuditRecord::organizationCreated($event->organizationId, $event->slug, $event->displayName, $actor),
                // The slug is unaffected by a name change, so reading it from the read model is safe
                // regardless of the order in which projectors run.
                $event instanceof OrganizationNameChanged => AuditRecord::organizationNameChanged($event->organizationId, $this->organization($event->organizationId)->slug, $event->displayName, $event->previousDisplayName, $actor),
                // The display name is unaffected by a slug change, so reading it from the read model is
                // safe regardless of the order in which projectors run.
                $event instanceof OrganizationSlugChanged => AuditRecord::organizationSlugChanged($event->organizationId, $event->slug, $this->organization($event->organizationId)->displayName, $event->previousSlug, $actor),
                default => throw new \LogicException('Unhandled event: ' . $event->eventType()->value),
            }
        );
    }

    private function organization(Ulid $id): Organization
    {
        $organization = $this->organizations->find($id);
        if ($organization === null) {
            throw new \LogicException('Organization read model not found for '.$id->toRfc4122().'.');
        }

        return $organization;
    }
}
