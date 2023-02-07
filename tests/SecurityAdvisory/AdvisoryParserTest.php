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

namespace App\Tests\SecurityAdvisory;

use App\SecurityAdvisory\AdvisoryParser;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class AdvisoryParserTest extends TestCase
{
    #[DataProvider('cveProvider')]
    public function testIsValidCve(bool $expected, string $cve): void
    {
        $this->assertSame($expected, AdvisoryParser::isValidCve($cve));
    }

    public static function cveProvider(): array
    {
        return [
            [true, 'CVE-2022-99999'],
            [false, 'CVE-2022-xxxx'],
        ];
    }

    #[DataProvider('titleProvider')]
    public function test(string $expected, string $title): void
    {
        $this->assertSame($expected, AdvisoryParser::titleWithoutCve($title));
    }

    public static function titleProvider(): array
    {
        return [
            ['CSRF token missing in forms', 'CVE-2022-99999999999: CSRF token missing in forms'],
            ['CSRF token missing in forms', 'CVE-2022-xxxx: CSRF token missing in forms'],
            ['CSRF token missing in forms', 'CVE-2022-XXXX: CSRF token missing in forms'],
            ['CSRF token missing in forms', 'CVE-2022-xxxx-2: CSRF token missing in forms'],
        ];
    }
}
