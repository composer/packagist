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
use App\Entity\OrganizationStatus;
use App\Entity\UserRepository;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\EventStore\RecordedEvent;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;

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
    ) {
    }

    public function project(RecordedEvent $recorded): void
    {
        $event = $recorded->event;

        if ($event instanceof OrganizationCreated) {
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
            $this->getEM()->flush();
        }
    }
}
