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

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

use App\SecurityAdvisory\Severity;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class SeverityTest extends TestCase
{
    #[DataProvider('gitHubSeverityProvider')]
    public function testFromGitHub(?string $gitHubSeverity, ?Severity $expected): void
    {
        $this->assertSame($expected, Severity::fromGitHub($gitHubSeverity));
    }

    public static function gitHubSeverityProvider(): iterable
    {
        yield ['CRITICAL', Severity::CRITICAL];
        yield ['HIGH', Severity::HIGH];
        yield ['MODERATE', Severity::MEDIUM];
        yield ['LOW', Severity::LOW];
        yield [null, null];
    }

    #[DataProvider('labelClassProvider')]
    public function testLabelClass(Severity $severity, string $expected): void
    {
        $this->assertSame($expected, $severity->labelClass());
    }

    public static function labelClassProvider(): iterable
    {
        yield [Severity::NONE, 'label-severity-none'];
        yield [Severity::LOW, 'label-severity-low'];
        yield [Severity::MEDIUM, 'label-severity-medium'];
        yield [Severity::HIGH, 'label-severity-high'];
        yield [Severity::CRITICAL, 'label-severity-critical'];
    }
}
