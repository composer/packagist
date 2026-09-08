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
 * Read-model projection of an org-level membership. A member's access is still derived from their team
 * memberships ({@see OrganizationTeamMember}); this row carries the org-scoped facts that team rows
 * cannot express (when they joined). It carries no role: an owner is simply a member of the
 * `owners` team.
 *
 * Maintained by {@see \App\Organization\Projection\OrganizationReadModelProjector} alongside team
 * membership so it stays consistent with the org aggregate, the source of truth for membership.
 */
#[ORM\Entity(repositoryClass: OrganizationMemberRepository::class)]
#[ORM\Table(name: 'organization_member')]
#[ORM\Index(name: 'org_member_user_idx', columns: ['userId'])]
class OrganizationMember
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $orgId,

        #[ORM\Id]
        #[ORM\Column]
        public readonly int $userId,

        #[ORM\Column(type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $joinedAt,
    ) {
    }
}
