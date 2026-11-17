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

namespace App\Tests\Security\Api;

use App\Entity\User;
use App\Security\Api\ApiToken;
use App\Security\Api\ApiType;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;

class ApiTokenTest extends TestCase
{
    #[TestWith([ApiType::Unsafe, true])]
    #[TestWith([ApiType::Safe, false])]
    public function testAllows(ApiType $tokenType, bool $allowsUnsafe): void
    {
        $token = new ApiToken(new User(), $tokenType);

        self::assertTrue($token->allows(ApiType::Safe));
        self::assertSame($allowsUnsafe, $token->allows(ApiType::Unsafe));
    }

    public function testApiTokensNeverCarryAdminRoles(): void
    {
        $user = new User();
        $user->addRole('ROLE_ADMIN');

        self::assertSame(['ROLE_USER'], new ApiToken($user, ApiType::Unsafe)->getRoleNames());
    }
}
