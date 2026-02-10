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

use App\Entity\FilterListEntry;

class FilterListResolver
{
    /**
     * @param array<FilterListEntry> $existingEntries
     * @param array<RemoteFilterListEntry> $remoteEntries
     * @return array<list<FilterListEntry>, list<FilterListEntry>>
     */
    public function resolve(array $existingEntries, array $remoteEntries): array
    {
        $existingMap = [];
        foreach ($existingEntries as $existing) {
            $existingMap[$existing->getPackageName()][$existing->getVersion()] = $existing;
        }

        $new = [];
        foreach ($remoteEntries as $remote) {
            if (isset($existingMap[$remote->packageName][$remote->version])) {
                unset($existingMap[$remote->packageName][$remote->version]);
                continue;
            }

            $new[] = new FilterListEntry($remote);
        }

        $unmatched = [];
        foreach ($existingMap as $existingPackageEntries) {
            foreach ($existingPackageEntries as $existingVersionEntry) {
                $unmatched[] = $existingVersionEntry;
            }
        }

        return [
            $new,
            $unmatched,
        ];
    }
}
