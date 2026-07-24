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

use App\Organization\Domain\OrganizationTeamKind;
use App\Organization\Domain\TeamName;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Read-model projection of a team within the Organization aggregate.
 *
 * @see \App\Organization\Domain\Organization for the write-side aggregate.
 */
#[ORM\Entity(repositoryClass: OrganizationTeamRepository::class)]
#[ORM\Table(name: 'organization_team')]
#[ORM\UniqueConstraint(name: 'org_team_name_uniq', columns: ['orgId', 'name'])]
#[ORM\Index(name: 'org_team_org_idx', columns: ['orgId'])]
class OrganizationTeam
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $teamId,

        #[ORM\ManyToOne(targetEntity: Organization::class)]
        #[ORM\JoinColumn(name: 'orgId', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public readonly Organization $organization,

        #[ORM\Column(length: 16)]
        public readonly OrganizationTeamKind $kind,

        #[ORM\Column(length: TeamName::MAX_LENGTH)]
        public string $name,

        /** Null once the creating user is deleted, or for system/bootstrap teams with no explicit creator. */
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'createdBy', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        public readonly ?User $createdBy,

        #[ORM\Column(type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,
    ) {
    }

    public function isSystem(): bool
    {
        return $this->kind === OrganizationTeamKind::System;
    }
}
