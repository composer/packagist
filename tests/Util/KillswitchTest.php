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

use App\Util\Killswitch;
use PHPUnit\Framework\TestCase;

class KillswitchTest extends TestCase
{
    public function testConstantsAreTrue(): void
    {
        // Arrange / Act / Assert
        self::assertTrue(Killswitch::WORKERS_ENABLED);
        self::assertTrue(Killswitch::LINKS_ENABLED);
        self::assertTrue(Killswitch::DOWNLOADS_ENABLED);
        self::assertTrue(Killswitch::PAGE_DETAILS_ENABLED);
        self::assertTrue(Killswitch::PAGES_ENABLED);
    }

    public function testIsEnabledReturnsPassedValue(): void
    {
        // Arrange
        $enabled = true;
        $disabled = false;

        // Act
        $resultEnabled = Killswitch::isEnabled($enabled);
        $resultDisabled = Killswitch::isEnabled($disabled);

        // Assert
        self::assertTrue($resultEnabled);
        self::assertFalse($resultDisabled);
    }

    public function testIsEnabledWithConstants(): void
    {
        // Arrange / Act / Assert
        self::assertTrue(Killswitch::isEnabled(Killswitch::WORKERS_ENABLED));
        self::assertTrue(Killswitch::isEnabled(Killswitch::PAGES_ENABLED));
    }
}
