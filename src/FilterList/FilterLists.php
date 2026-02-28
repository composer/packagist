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

namespace App\FilterList;

enum FilterLists: string
{
    case AIKIDO_MALWARE = 'aikido';

    public function logo(): string
    {
        return match ($this) {
            self::AIKIDO_MALWARE => 'img/aikido-dark.svg',
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::AIKIDO_MALWARE => 'Aikido',
        };
    }

    public function url(): string
    {
        return match ($this) {
            self::AIKIDO_MALWARE => 'https://aikido.dev/',
        };
    }
}
