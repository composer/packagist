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

namespace App\Support;

/** What should happen to the packages that stop the account being deleted self-service. */
enum PackageDisposition: string
{
    case Transfer = 'transfer';
    case Abandon = 'abandon';
    case Delete = 'delete';
    case Undecided = 'undecided';

    public function label(): string
    {
        return match ($this) {
            self::Transfer => 'Transfer them to another account',
            self::Abandon => 'Mark them abandoned and leave them up',
            self::Delete => 'Delete them along with my account',
            self::Undecided => 'I am not sure, please advise',
        };
    }
}
