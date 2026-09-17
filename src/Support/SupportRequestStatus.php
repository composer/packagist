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

enum SupportRequestStatus: string
{
    case Open = 'open';

    /** The request was granted and the action carried out. */
    case Resolved = 'resolved';

    /** No action taken: declined, duplicate, spam, or cancelled by the account owner. */
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /** Bootstrap contextual class used for the status badge in the admin queue. */
    public function badgeClass(): string
    {
        return match ($this) {
            self::Open => 'bg-warning text-dark',
            self::Resolved => 'bg-success',
            self::Closed => 'bg-secondary',
        };
    }
}
