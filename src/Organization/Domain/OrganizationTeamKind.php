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

namespace App\Organization\Domain;

/**
 * Classifies a team so the {@see Organization} aggregate's protection guards know which teams are
 * off-limits. The two teams bootstrapped when an org is created (`owners` and `all organization
 * members`) are {@see self::System}; teams users create themselves are {@see self::Custom} and can
 * be freely renamed or deleted.
 *
 * The backing values are persisted in the {@see \App\Organization\Domain\Event\TeamCreated} event
 * payload and the {@see \App\Entity\OrganizationTeam} read model, so they must remain stable.
 */
enum OrganizationTeamKind: string
{
    /** The bootstrapped `owners` / `all organization members` teams: protected from rename/delete. */
    case System = 'system';

    case Custom = 'custom';
}
