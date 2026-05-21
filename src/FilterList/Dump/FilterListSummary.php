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

final readonly class FilterListSummary
{
    /**
     * @param array<string, array<string, string>> $byList list value → packageName → combined `|`-joined constraint
     */
    public function __construct(
        public array $byList,
    ) {
    }

    /**
     * @return array{filter: array<string, array<string, string>>}
     */
    public function toJsonPayload(): array
    {
        return ['filter' => $this->byList];
    }
}
