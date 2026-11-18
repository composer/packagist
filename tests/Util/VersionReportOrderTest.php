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

namespace App\Tests\Util;

use App\Util\VersionReportOrder;
use PHPUnit\Framework\TestCase;

class VersionReportOrderTest extends TestCase
{
    public function testSortsNewestVersionFirstThenEarliestReportFirst(): void
    {
        $reports = [
            ['1.9.0', null],
            ['1.10.0', '2026-03-01'],
            ['1.10.0', null],
            ['1.10.0', '2026-01-01'],
            ['v2.0.0', '2026-05-01'],
            ['1.2.0', '2026-01-01'],
        ];

        $this->assertSame([
            ['v2.0.0', '2026-05-01'],
            ['1.10.0', '2026-01-01'],
            ['1.10.0', '2026-03-01'],
            ['1.10.0', null],
            ['1.9.0', null],
            ['1.2.0', '2026-01-01'],
        ], $this->sort($reports));
    }

    public function testRanksConstraintsByTheirUpperBound(): void
    {
        $this->assertSame([
            ['dev-main', null],
            ['>=2.0', null],
            ['<1.5', null],
            ['1.4.0', null],
            ['not a version', null],
        ], $this->sort([
            ['1.4.0', null],
            ['not a version', null],
            ['<1.5', null],
            ['dev-main', null],
            ['>=2.0', null],
        ]));
    }

    public function testKeepsTheOrderOfOtherwiseEqualReports(): void
    {
        $this->assertSame([
            ['1.0.0', null, 'first'],
            ['1.0.0', null, 'second'],
        ], $this->sort([
            ['1.0.0', null, 'first'],
            ['1.0.0', null, 'second'],
        ]));
    }

    /**
     * @template T of array{0: string, 1: string|null}
     *
     * @param list<T> $reports
     *
     * @return list<T>
     */
    private function sort(array $reports): array
    {
        return VersionReportOrder::sort(
            $reports,
            static fn (array $report) => $report[0],
            static fn (array $report) => $report[1] === null ? null : new \DateTimeImmutable($report[1]),
        );
    }
}
