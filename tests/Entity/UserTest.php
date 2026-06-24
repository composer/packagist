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

namespace App\Tests\Entity;

use App\Entity\User;
use App\Entity\UserFreezeReason;
use PHPUnit\Framework\TestCase;

class UserTest extends TestCase
{
    public function testFreezeUnfreeze(): void
    {
        $user = new User();
        self::assertFalse($user->isFrozen());
        self::assertNull($user->getFreezeReason());

        $user->freeze(UserFreezeReason::Spam);
        self::assertTrue($user->isFrozen());
        self::assertSame(UserFreezeReason::Spam, $user->getFreezeReason());

        $user->unfreeze();
        self::assertFalse($user->isFrozen());
        self::assertNull($user->getFreezeReason());
    }

    public function testIsAccountActiveRequiresEnabledAndNotFrozen(): void
    {
        $user = new User();
        $user->setEnabled(true);
        self::assertTrue($user->isAccountActive());

        $user->setEnabled(false);
        self::assertFalse($user->isAccountActive(), 'unconfirmed email');

        $user->setEnabled(true);
        $user->freeze(UserFreezeReason::BadActor);
        self::assertFalse($user->isAccountActive(), 'frozen');
    }

    public function testSerializeRoundTripPreservesFreezeReason(): void
    {
        $user = $this->makeUser();
        $user->freeze(UserFreezeReason::Temporary);

        $restored = new User();
        $restored->__unserialize($user->__serialize());

        self::assertTrue($restored->isFrozen());
        self::assertSame(UserFreezeReason::Temporary, $restored->getFreezeReason());
    }

    public function testUnserializeOldSessionWithoutFrozenKeyDefaultsToNotFrozen(): void
    {
        // Sessions serialized before the frozen field existed have no 'frozen' key.
        $data = $this->makeUser()->__serialize();
        unset($data['frozen']);

        $restored = new User();
        $restored->__unserialize($data);

        self::assertFalse($restored->isFrozen());
        self::assertNull($restored->getFreezeReason());
    }

    public function testIsEqualToDetectsFreezeChange(): void
    {
        $a = $this->makeUser();
        $b = $this->makeUser();
        self::assertTrue($a->isEqualTo($b));

        $b->freeze(UserFreezeReason::Spam);
        self::assertFalse($a->isEqualTo($b), 'freezing must invalidate the session token');
    }

    private function makeUser(): User
    {
        $user = new User();
        $user->setUsername('sameuser');
        $user->setEmail('same@example.com');
        $user->setPassword('samehash');
        $user->setEnabled(true);
        // __serialize() reads the id, which Doctrine would normally assign on persist.
        new \ReflectionProperty($user, 'id')->setValue($user, 1);

        return $user;
    }
}
