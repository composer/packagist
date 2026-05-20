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
use Composer\Semver\VersionParser;
use Psr\Log\LoggerInterface;

class FilterListResolver
{
    public function __construct(
        private readonly LoggerInterface $logger,
    ) {
    }

    /**
     * @param array<FilterListEntry>       $existingEntries
     * @param array<RemoteFilterListEntry> $remoteEntries
     *
     * @return array{list<FilterListEntry>, list<FilterListEntry>}
     */
    public function resolve(array $existingEntries, array $remoteEntries): array
    {
        $existingMap = [];
        foreach ($existingEntries as $existing) {
            $existingMap[$existing->getPackageName()][$existing->getRemoteVersion()] = $existing;
        }

        $versionParser = new VersionParser();
        $new = [];
        $found = [];
        foreach ($remoteEntries as $remote) {
            try {
                $versionParser->parseConstraints($remote->version);
            } catch (\UnexpectedValueException $e) {
                $this->logger->warning('Skipping filter list entry with invalid version constraint', [
                    'entry' => $remote,
                    'exception' => $e,
                ]);
                continue;
            }

            if (isset($existingMap[$remote->packageName][$remote->version])) {
                $found[$remote->packageName][$remote->version] = true;
                continue;
            }

            $new[] = new FilterListEntry($remote);
        }

        $unmatched = [];
        foreach ($existingMap as $existingPackageEntries) {
            foreach ($existingPackageEntries as $existingVersionEntry) {
                if (!isset($found[$existingVersionEntry->getPackageName()][$existingVersionEntry->getRemoteVersion()])) {
                    $unmatched[] = $existingVersionEntry;
                }
            }
        }

        return [
            $new,
            $unmatched,
        ];
    }
}
