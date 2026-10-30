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

namespace App\Package\Git;

/**
 * Object types as numbered in pack files
 */
enum ObjectType: int
{
    case Commit = 1;
    case Tree = 2;
    case Blob = 3;
    case Tag = 4;

    public function label(): string
    {
        return match ($this) {
            self::Commit => 'commit',
            self::Tree => 'tree',
            self::Blob => 'blob',
            self::Tag => 'tag',
        };
    }
}
