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

namespace App\Security\Voter;

/**
 * Organization actions guarded by {@see OrganizationVoter}.
 */
enum OrganizationActions: string
{
    case Edit = 'edit';
    // Groundwork for org deletion (not yet implemented).
    case SoftDelete = 'soft-delete';
    case Restore = 'restore';

    // Team & member management — owner-only.
    case CreateTeam = 'create-team';
    case RenameTeam = 'rename-team';
    case DeleteTeam = 'delete-team';
    case AddTeamMember = 'add-team-member';
    case RemoveTeamMember = 'remove-team-member';
    case RemoveMember = 'remove-member';

    // Any org member may leave on their own.
    case Leave = 'leave';
}
