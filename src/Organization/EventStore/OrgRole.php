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

namespace App\Organization\EventStore;

/**
 * The org role that authorized an action. Only `owner` exists at this stage;
 * packagist-admin moderation carries no org role (null).
 */
enum OrgRole: string
{
    case Owner = 'owner';
}
