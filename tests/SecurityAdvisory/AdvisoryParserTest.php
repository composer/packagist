<?php declare(strict_types=1);

namespace App\Tests\SecurityAdvisory;

use App\SecurityAdvisory\AdvisoryParser;
use PHPUnit\Framework\TestCase;

class AdvisoryParserTest extends TestCase
{
    /**
     * @dataProvider cveProvider
     */
    public function testIsValidCve(bool $expected, string $cve): void
    {
        $this->assertSame($expected, AdvisoryParser::isValidCve($cve));
    }

    public function cveProvider(): array
    {
        return [
            [true, 'CVE-2022-99999'],
            [false, 'CVE-2022-xxxx'],
        ];
    }

    /**
     * @dataProvider titleProvider
     */
    public function test(string $expected, string $title): void
    {
        $this->assertSame($expected, AdvisoryParser::titleWithoutCve($title));
    }

    public function titleProvider(): array
    {
        return [
            ['CSRF token missing in forms', 'CVE-2022-99999999999: CSRF token missing in forms'],
            ['CSRF token missing in forms', 'CVE-2022-xxxx: CSRF token missing in forms'],
            ['CSRF token missing in forms', 'CVE-2022-XXXX: CSRF token missing in forms'],
            ['CSRF token missing in forms', 'CVE-2022-xxxx-2: CSRF token missing in forms'],
        ];
    }
}
