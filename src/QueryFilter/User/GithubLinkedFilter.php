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

class GithubLinkedFilter extends AbstractNullableColumnFilter
{
    protected static function key(): string
    {
        return 'github_linked';
    }

    protected static function column(): string
    {
        return 'u.githubId';
    }

    protected static function values(): array
    {
        return ['yes', 'no'];
    }
}
