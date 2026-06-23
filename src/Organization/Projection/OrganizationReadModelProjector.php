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
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\EventStore\RecordedEvent;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;

/**
 * Projects the organization event stream into the `organization` read-model table.
 * The slug unique constraint turns a concurrent duplicate into a transaction
 * rollback, which the application service maps to SlugTaken.
 */
final class OrganizationReadModelProjector implements Projector
{
    use DoctrineTrait;

    public function __construct(
        private readonly ManagerRegistry $doctrine,
    ) {
    }

    public function project(RecordedEvent $recorded): void
    {
        $event = $recorded->event;

        if ($event instanceof OrganizationCreated) {
            $this->getEM()->persist(new Organization(
                $event->organizationId,
                $event->slug,
                $event->displayName,
                OrganizationStatus::Active,
                $recorded->occurredAt,
                $recorded->actor->userId,
            ));
            $this->getEM()->flush();
        }
    }
}
