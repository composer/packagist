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

namespace App\QueryFilter\TransparencyLog;

use App\Log\TransparencyLogSearchType;

/**
 * Entries done by a username.
 */
class ActorFilter extends AbstractSearchIndexFilter
{
    protected static function key(): string
    {
        return 'actor';
    }

    protected static function type(): TransparencyLogSearchType
    {
        return TransparencyLogSearchType::Actor;
    }
}
