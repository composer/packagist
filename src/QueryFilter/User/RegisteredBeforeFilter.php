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

namespace App\QueryFilter\User;

class RegisteredBeforeFilter extends AbstractRegisteredDateFilter
{
    protected static function key(): string
    {
        return 'registered_to';
    }

    protected static function operator(): string
    {
        return '<=';
    }

    protected static function endOfDay(): bool
    {
        return true;
    }
}
