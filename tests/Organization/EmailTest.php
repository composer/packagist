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

namespace App\Tests\Organization;

use App\Organization\Domain\Email;
use PHPUnit\Framework\TestCase;

class EmailTest extends TestCase
{
    public function testConstructKeepsTheGivenCasing(): void
    {
        self::assertSame('Alice@Example.org', (new Email('  Alice@Example.org  '))->value);
    }

    public function testIsIdenticalIgnoresCasing(): void
    {
        $email = new Email('Alice@Example.org');

        self::assertTrue($email->isIdentical('alice@example.org'));
        self::assertTrue($email->isIdentical(new Email('ALICE@EXAMPLE.ORG')));
    }

    public function testIsIdenticalRejectsAnotherAddress(): void
    {
        self::assertFalse((new Email('alice@example.org'))->isIdentical('bob@example.org'));
    }
}
