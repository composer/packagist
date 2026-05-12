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
    case MALWARE = 'malware';

    /**
     * @return array<string, array{enabled: bool}>
     */
    public static function packagesJsonListConfig(): array
    {
        $lists = [];
        foreach (self::cases() as $list) {
            $lists[$list->value] = ['enabled' => true];
        }

        return $lists;
    }
}
