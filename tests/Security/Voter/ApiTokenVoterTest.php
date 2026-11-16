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

namespace App\Tests\Security\Voter;

use App\Entity\User;
use App\Security\Api\ApiToken;
use App\Security\Api\ApiType;
use App\Security\Voter\ApiTokenVoter;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorage;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManager;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;

class ApiTokenVoterTest extends TestCase
{
    #[TestWith([ApiType::Unsafe, true, true])]
    #[TestWith([ApiType::Safe, true, false])]
    public function testApiTokens(ApiType $tokenType, bool $safeGranted, bool $unsafeGranted): void
    {
        $authorization = $this->authorizationFor(new ApiToken(new User(), $tokenType));

        self::assertSame($safeGranted, $authorization->isGranted(ApiType::Safe->value));
        self::assertSame($unsafeGranted, $authorization->isGranted(ApiType::Unsafe->value));
    }

    public function testApiTokensNeverCarryAdminRoles(): void
    {
        $user = new User();
        $user->addRole('ROLE_ADMIN');

        self::assertSame(['ROLE_USER'], new ApiToken($user, ApiType::Unsafe)->getRoleNames());
    }

    public function testWebSessionsAreNotApiAuthenticated(): void
    {
        $user = new User();
        $user->addRole('ROLE_ADMIN');
        $authorization = $this->authorizationFor(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        self::assertFalse($authorization->isGranted(ApiType::Safe->value));
        self::assertFalse($authorization->isGranted(ApiType::Unsafe->value));
    }

    private function authorizationFor(TokenInterface $token): AuthorizationChecker
    {
        $tokens = new TokenStorage();
        $tokens->setToken($token);

        return new AuthorizationChecker($tokens, new AccessDecisionManager([new ApiTokenVoter()]));
    }
}
