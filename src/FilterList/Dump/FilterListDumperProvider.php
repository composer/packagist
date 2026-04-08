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

namespace App\FilterList\Dump;

use App\Entity\FilterListEntry;
use App\Util\DoctrineTrait;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

final readonly class FilterListDumperProvider
{
    use DoctrineTrait;

    public function __construct(
        private ManagerRegistry $doctrine,
        private UrlGeneratorInterface $urlGenerator,
    ) {
    }

    /**
     * @param string[] $packageNames
     *
     * @return array<string, array<string, list<DumpableFilterList>>>
     */
    public function getEntriesForDump(array $packageNames): array
    {
        $allPackageEntries = $this->getEM()->getRepository(FilterListEntry::class)->getAllPackageEntriesMap($packageNames);

        $groupedEntries = [];
        foreach ($allPackageEntries as $packageName => $entries) {
            foreach ($entries as $entry) {
                $groupedEntries[$packageName][$entry['list']][] = new DumpableFilterList(
                    $entry['version'],
                    $this->urlGenerator->generate('view_package_filter_lists', ['name' => $packageName], UrlGeneratorInterface::ABSOLUTE_URL),
                    $entry['reason'],
                    $entry['publicId'],
                );
            }
        }

        return $groupedEntries;
    }
}
