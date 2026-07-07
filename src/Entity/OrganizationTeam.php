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

use App\Organization\Domain\TeamName;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

enum OrganizationTeamKind: string
{
    /** The bootstrapped `owners` team: org-wide access, protected from rename/delete. */
    case System = 'system';
    case Custom = 'custom';
}

/**
 * Read-model projection of a team within the Organization aggregate.
 *
 * @see \App\Organization\Domain\Organization for the write-side aggregate.
 */
#[ORM\Entity(repositoryClass: OrganizationTeamRepository::class)]
#[ORM\Table(name: 'organization_team')]
#[ORM\UniqueConstraint(name: 'org_team_name_uniq', columns: ['orgId', 'nameLower'])]
#[ORM\Index(name: 'org_team_org_idx', columns: ['orgId'])]
class OrganizationTeam
{
    /** Generated lowercase mirror of `name` backing the case-insensitive uniqueness constraint. */
    #[ORM\Column(insertable: false, updatable: false, columnDefinition: 'VARCHAR(' . TeamName::MAX_LENGTH . ') GENERATED ALWAYS AS (LOWER(name)) STORED')]
    public string $nameLower = '';

    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $teamId,

        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $orgId,

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

    public function rename(string $name): void
    {
        $this->name = $name;
    }
}
