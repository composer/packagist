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
 * Read-model projection of an invitation's target team. An invitation grants membership in one or more
 * teams on acceptance, so the target teams live in this companion table rather than a column on
 * {@see OrganizationInvitation}.
 *
 * There is deliberately no foreign key to {@see OrganizationTeam}: a team may be deleted after the
 * invitation is sent, and the historical target set must survive so acceptance can report that a target
 * team no longer exists.
 */
#[ORM\Entity(repositoryClass: OrganizationInvitationTeamRepository::class)]
#[ORM\Table(name: 'organization_invitation_team')]
class OrganizationInvitationTeam
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $invitationId,

        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $teamId,
    ) {
    }
}
