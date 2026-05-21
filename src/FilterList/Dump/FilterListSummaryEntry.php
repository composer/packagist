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

use App\FilterList\FilterLists;

final readonly class FilterListSummaryEntry
{
    public function __construct(
        public string $packageName,
        public FilterLists $list,
        public string $version,
    ) {}
}
