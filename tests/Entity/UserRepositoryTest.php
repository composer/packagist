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

use App\Entity\UserRepository;
use App\Tests\IntegrationTestCase;

class UserRepositoryTest extends IntegrationTestCase
{
    private UserRepository $userRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->userRepository = self::getEM()->getRepository(\App\Entity\User::class);
    }

    public function testFindUsersByUsernameWithMultipleValidUsernames(): void
    {
        $alice = self::createUser('Alice', 'alice@example.org');
        $bob = self::createUser('Bob', 'bob@example.org');
        $charlie = self::createUser('Charlie', 'charlie@example.org');
        $this->store($alice, $bob, $charlie);

        $result = $this->userRepository->findUsersByUsername(['alice', 'bob']);

        $this->assertCount(2, $result);

        $this->assertArrayHasKey('alice', $result);
        $this->assertArrayHasKey('bob', $result);

        $this->assertSame($alice->getId(), $result['alice']->getId());
        $this->assertSame($bob->getId(), $result['bob']->getId());
    }
}
