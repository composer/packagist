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
    case AIKIDO_MALWARE = 'aikido-malware';

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

    /**
     * @return list<FilterLists>
     */
    public static function defaultLists(): array
    {
        return [self::AIKIDO_MALWARE];
    }

    /**
     * @return list<FilterLists>
     */
    public static function malwareLists(): array
    {
        return [self::AIKIDO_MALWARE];
    }

    /**
     * @return list<string>
     */
    public static function malwareListsValues(): array
    {
        return array_map(fn (FilterLists $list) => $list->value, self::malwareLists());
    }

    public static function fromWithBackwardsCompatibility(string $list): self
    {
        return self::from($list === 'aikido' ? self::AIKIDO_MALWARE->value : $list);
    }
}
