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
 * Who/what triggered an action, recorded separately from the org role that authorized it.
 */
enum ActorLabel: string
{
    case User = 'user';
    case PackagistAdmin = 'packagist-admin';
    case Automation = 'automation';
}
