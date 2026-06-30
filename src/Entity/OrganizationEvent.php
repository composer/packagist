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

namespace App\Entity;

use App\Organization\EventStore\OrganizationEventType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Canonical event stream for the organization domain. Appended to by
 * {@see \App\Organization\EventStore\EventStore}; read models
 * (`organization`, `audit_log`) are projections rebuildable from it. The unique
 * (aggregateId, sequence) constraint enforces optimistic concurrency.
 */
#[ORM\Entity(repositoryClass: OrganizationEventRepository::class)]
#[ORM\Table(name: 'organization_event')]
#[ORM\UniqueConstraint(name: 'org_event_seq_idx', columns: ['aggregateId', 'sequence'])]
#[ORM\Index(name: 'org_event_actor_idx', columns: ['actorUserId', 'createdAt'])]
class OrganizationEvent
{
    /**
     * @param array<string, mixed> $payload
     */
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $id,

        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $aggregateId,

        #[ORM\Column(options: ['unsigned' => true])]
        public readonly int $sequence,

        #[ORM\Column(length: 64)]
        public readonly OrganizationEventType $type,

        #[ORM\Column(type: Types::JSON)]
        public readonly array $payload,

        #[ORM\Column(length: 32)]
        public readonly string $actorLabel,

        #[ORM\Column(type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,

        #[ORM\Column(nullable: true)]
        public readonly ?int $actorUserId = null,

        #[ORM\Column(length: 32, nullable: true)]
        public readonly ?string $actorRoleInOrg = null,

        #[ORM\Column(nullable: true, type: 'ipaddress')]
        public readonly ?string $ip = null,
    ) {
    }
}
