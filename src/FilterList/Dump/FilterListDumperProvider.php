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
    ) {}

    /**
     * @param string[] $packageNames
     * @return array<string, array<string, list<DumpableFilterList>>>
     */
    public function getMalwareDataForDump(array $packageNames): array
    {
        $malwarePackageVersions = $this->getEM()->getRepository(FilterListEntry::class)->getPackageVersionsFlaggedAsMalwareForPackageNames($packageNames);

        $groupedEntries = [];
        foreach ($malwarePackageVersions as $packageName => $entries) {
            $packageGroup = [];
            foreach ($entries as $entry) {
                $packageGroup[$entry['list']][$entry['category']][] = $entry['version'];
            }

            foreach ($packageGroup as $list => $categories) {
                foreach ($categories as $category => $versions) {
                    $groupedEntries[$packageName][$list][] = new DumpableFilterList(
                        implode(' || ', $versions),
                        $this->urlGenerator->generate('filter_list_view', ['list' => $list], UrlGeneratorInterface::ABSOLUTE_URL),
                        $category,
                        null,
                    );
                }
            }
        }

        return $groupedEntries;
    }
}
