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

namespace App\Tests\Security;

use App\Entity\UserFreezeReason;
use App\Security\UserChecker;
use App\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Exception\CustomUserMessageAccountStatusException;

class UserCheckerTest extends TestCase
{
    use Fixtures;

    public function testActiveUserPasses(): void
    {
        $this->expectNotToPerformAssertions();

        new UserChecker()->checkPreAuth(self::createUser());
    }

    public function testFrozenUserIsRejected(): void
    {
        $user = self::createUser();
        $user->freeze(UserFreezeReason::Spam);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('Your account has been disabled.');

        new UserChecker()->checkPreAuth($user);
    }

    public function testUnconfirmedUserIsRejected(): void
    {
        $user = self::createUser(enabled: false);

        $this->expectException(CustomUserMessageAccountStatusException::class);
        $this->expectExceptionMessage('not yet enabled');

        new UserChecker()->checkPreAuth($user);
    }
}
