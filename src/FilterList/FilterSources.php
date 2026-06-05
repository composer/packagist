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

enum FilterSources: string
{
    case AIKIDO = 'aikido';
    case PACKAGIST = 'packagist';

    /**
     * Path to the reporting source's logo, or null when the entry was created
     * manually and has no external reporter.
     */
    public function logo(): ?string
    {
        return match ($this) {
            self::AIKIDO => 'img/aikido-dark.svg',
            self::PACKAGIST => null,
        };
    }

    public function displayName(): string
    {
        return match ($this) {
            self::AIKIDO => 'Aikido',
            self::PACKAGIST => 'Packagist',
        };
    }

    /**
     * URL of the reporting source, or null when the entry was created manually
     * and has no external reference.
     */
    public function url(): ?string
    {
        return match ($this) {
            self::AIKIDO => 'https://aikido.dev/',
            self::PACKAGIST => null,
        };
    }
}
