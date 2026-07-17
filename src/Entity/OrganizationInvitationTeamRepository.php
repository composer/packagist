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

use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * @extends ServiceEntityRepository<OrganizationInvitationTeam>
 */
class OrganizationInvitationTeamRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, OrganizationInvitationTeam::class);
    }

    /**
     * The target team ids of an invitation.
     *
     * @return list<Ulid>
     */
    public function findTeamIds(Ulid $invitationId): array
    {
        return array_map(
            static fn (OrganizationInvitationTeam $row): Ulid => $row->teamId,
            $this->findBy(['invitationId' => $invitationId]),
        );
    }
}
