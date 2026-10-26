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

use App\Util\Rfc3339;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class Rfc3339Test extends TestCase
{
    #[TestWith(['2026-10-18T14:30:00Z', '2026-10-18 14:30:00.000000'])]
    #[TestWith(['2026-10-18T16:30:00+02:00', '2026-10-18 14:30:00.000000'])]
    #[TestWith(['2026-10-18T14:30:00.123Z', '2026-10-18 14:30:00.123000'])]
    public function testParsesIntoUtc(string $value, string $expected): void
    {
        $date = Rfc3339::parse($value);

        $this->assertNotNull($date);
        $this->assertSame($expected, $date->format('Y-m-d H:i:s.u'));
        $this->assertSame('UTC', $date->getTimezone()->getName());
    }

    #[TestWith(['yesterday'])]
    #[TestWith(['+1 week'])]
    #[TestWith(['2026-10-18 14:30:00'])]
    #[TestWith(['2026-10-18T14:30:00'])]
    #[TestWith(['2026-10-18'])]
    #[TestWith([''])]
    #[TestWith([null])]
    #[TestWith([1760797800])]
    public function testRejectsAnythingElse(mixed $value): void
    {
        $this->assertNull(Rfc3339::parse($value));
    }
}
