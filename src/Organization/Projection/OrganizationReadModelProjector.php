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

use App\Entity\Organization;
use App\Entity\OrganizationRepository;
use App\Entity\OrganizationStatus;
use App\Entity\SlugReservation;
use App\Entity\SlugReservationKind;
use App\Entity\SlugReservationRepository;
use App\Entity\UserRepository;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Event\OrganizationNameChanged;
use App\Organization\Domain\Event\OrganizationSlugChanged;
use App\Organization\EventStore\RecordedEvent;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * Projects the organization event stream into the `organization` read-model table.
 * The slug unique constraint turns a concurrent duplicate into a transaction
 * rollback, which the application service maps to SlugTaken.
 */
final readonly class OrganizationReadModelProjector implements Projector
{
    use DoctrineTrait;

    public function __construct(
        private ManagerRegistry $doctrine,
        private UserRepository $users,
        private OrganizationRepository $organizations,
        private SlugReservationRepository $reservations,
    ) {
    }

    public function project(RecordedEvent $recorded): void
    {
        $event = $recorded->event;

        match (true) {
            $event instanceof OrganizationCreated => $this->organizationCreated($recorded, $event),
            $event instanceof OrganizationNameChanged => $this->organizationNameChanged($event),
            $event instanceof OrganizationSlugChanged => $this->organizationSlugChanged($recorded, $event),
            default => throw new \LogicException('Unhandled event: ' . $event->eventType()->value),
        };

        $this->getEM()->flush();
    }

    private function organizationCreated(RecordedEvent $recorded, OrganizationCreated $event): void
    {
        $createdBy = $recorded->actor->userId !== null
            ? $this->users->find($recorded->actor->userId)
            : null;

        $this->getEM()->persist(new Organization(
            $event->organizationId,
            $event->slug,
            $event->displayName,
            OrganizationStatus::Active,
            $recorded->occurredAt,
            $createdBy,
        ));
    }

    private function organizationNameChanged(OrganizationNameChanged $event): void
    {
        $this->organization($event->organizationId)->changeName($event->displayName);
    }

    private function organizationSlugChanged(RecordedEvent $recorded, OrganizationSlugChanged $event): void
    {
        $this->organization($event->organizationId)->changeSlug($event->slug);

        // Reclaiming a slug the org previously freed (e.g. acme -> acme-inc -> acme):
        // release the now-stale reservation (kept for the audit trail) so the slug is live
        // again and the active-slug unique constraint stays free for a future rename away.
        $reclaimed = $this->reservations->findActiveForOrg($event->slug, $event->organizationId);
        $reclaimed?->release($recorded->occurredAt);

        // Reserve the freed slug.
        $this->getEM()->persist(new SlugReservation(
            new Ulid(),
            $event->previousSlug,
            $event->organizationId,
            SlugReservationKind::RenamedFrom,
            $recorded->occurredAt,
        ));
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
