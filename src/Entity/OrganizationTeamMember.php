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

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Read-model projection of a team membership. Membership always lives inside a team;
 * a user's org membership is the union of their team memberships. `orgId` is denormalized
 * so org-scoped queries skip the join to {@see OrganizationTeam}.
 *
 * @see \App\Organization\Domain\Organization for the write-side aggregate.
 */
#[ORM\Entity(repositoryClass: OrganizationTeamMemberRepository::class)]
#[ORM\Table(name: 'organization_team_member')]
#[ORM\Index(name: 'org_team_member_org_user_idx', columns: ['orgId', 'userId'])]
#[ORM\Index(name: 'org_team_member_user_idx', columns: ['userId'])]
#[ORM\Index(name: 'org_team_member_team_idx', columns: ['teamId'])]
class OrganizationTeamMember
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $teamId,

        #[ORM\Id]
        #[ORM\Column]
        public readonly int $userId,

        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $orgId,

        /** Null once the adding user is deleted, or for bootstrap/self-join memberships with no explicit actor. */
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'addedBy', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        public readonly ?User $addedBy,

        #[ORM\Column(type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $addedAt,
    ) {
    }
}
