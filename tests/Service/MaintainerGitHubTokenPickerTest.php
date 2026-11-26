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

namespace App\Tests\Service;

use App\Entity\User;
use App\Service\MaintainerGitHubTokenPicker;
use App\Tests\Fixtures\Fixtures;
use App\Util\GitHubTokenRefusal;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Cache\Adapter\FilesystemAdapter;

class MaintainerGitHubTokenPickerTest extends TestCase
{
    use Fixtures;

    public function testPicksTheFirstMaintainerWithAToken(): void
    {
        $package = self::createPackage('acme/lib', 'https://github.com/acme/lib', maintainers: [
            self::maintainer('alice', null),
            self::maintainer('bob', 'bob-token'),
            self::maintainer('carol', 'carol-token'),
        ]);

        $token = new MaintainerGitHubTokenPicker(new ArrayAdapter())->pick($package, 'Acme');

        self::assertNotNull($token);
        self::assertSame('bob-token', $token->token);
        self::assertSame('bob', $token->username);
        self::assertSame('acme', $token->owner);
    }

    public function testReturnsNullWithoutMaintainerTokens(): void
    {
        $package = self::createPackage('acme/lib', 'https://github.com/acme/lib', maintainers: [
            self::maintainer('alice', null),
            self::maintainer('bob', ''),
        ]);

        self::assertNull(new MaintainerGitHubTokenPicker(new ArrayAdapter())->pick($package, 'acme'));
    }

    public function testSkipsATokenRefusedByTheOwnersIpAllowList(): void
    {
        $package = self::createPackage('acme/lib', 'https://github.com/acme/lib', maintainers: [
            self::maintainer('bob', 'bob-token'),
            self::maintainer('carol', 'carol-token'),
        ]);
        $picker = new MaintainerGitHubTokenPicker(new ArrayAdapter());

        $bob = $picker->pick($package, 'acme');
        self::assertNotNull($bob);
        $picker->recordRefusal($bob, GitHubTokenRefusal::IpAllowList);

        self::assertSame('carol-token', $picker->pick($package, 'ACME')?->token);
    }

    public function testIpAllowListSkipOnlyAppliesToThatOwner(): void
    {
        $package = self::createPackage('acme/lib', 'https://github.com/acme/lib', maintainers: [self::maintainer('bob', 'bob-token')]);
        $picker = new MaintainerGitHubTokenPicker(new ArrayAdapter());

        $bob = $picker->pick($package, 'acme');
        self::assertNotNull($bob);
        $picker->recordRefusal($bob, GitHubTokenRefusal::IpAllowList);

        self::assertNull($picker->pick($package, 'acme'));
        self::assertSame('bob-token', $picker->pick($package, 'other-org')?->token);
    }

    public function testUsernameWithReservedCacheKeyCharacters(): void
    {
        $package = self::createPackage('acme/lib', 'https://github.com/acme/lib', maintainers: [self::maintainer('amc:)@{x}', 'bob-token')]);
        // ArrayAdapter only validates keys in assert(), the prod adapters always do
        $cache = new FilesystemAdapter('token-picker-test-'.bin2hex(random_bytes(4)));
        $picker = new MaintainerGitHubTokenPicker($cache);

        $bob = $picker->pick($package, 'acme');
        self::assertNotNull($bob);
        $picker->recordRefusal($bob, GitHubTokenRefusal::IpAllowList);
        $picker->recordRefusal($bob, GitHubTokenRefusal::SamlSso);

        self::assertNull($picker->pick($package, 'acme'));
        self::assertSame('bob-token', $picker->pick($package, 'other-org')?->token);
        $cache->clear();
    }

    public function testSamlSsoSkipOnlyAppliesToThatOwner(): void
    {
        $package = self::createPackage('acme/lib', 'https://github.com/acme/lib', maintainers: [self::maintainer('bob', 'bob-token')]);
        $picker = new MaintainerGitHubTokenPicker(new ArrayAdapter());

        $bob = $picker->pick($package, 'acme');
        self::assertNotNull($bob);
        $picker->recordRefusal($bob, GitHubTokenRefusal::SamlSso);

        self::assertNull($picker->pick($package, 'acme'));
        self::assertSame('bob-token', $picker->pick($package, 'other-org')?->token);
    }

    public function testRejectedTokenIsSkippedForEveryOwnerUntilReplaced(): void
    {
        $bobUser = self::maintainer('bob', 'bob-token');
        $package = self::createPackage('acme/lib', 'https://github.com/acme/lib', maintainers: [$bobUser, self::maintainer('carol', 'carol-token')]);
        $picker = new MaintainerGitHubTokenPicker(new ArrayAdapter());

        $bob = $picker->pick($package, 'acme');
        self::assertNotNull($bob);
        $picker->recordRefusal($bob, GitHubTokenRefusal::Rejected);

        self::assertSame('carol-token', $picker->pick($package, 'acme')?->token);
        self::assertSame('carol-token', $picker->pick($package, 'other-org')?->token);

        $bobUser->setGithubToken('bob-new-token');
        self::assertSame('bob-new-token', $picker->pick($package, 'acme')?->token);
    }

    public function testRateLimitedTokenIsNotSkipped(): void
    {
        $package = self::createPackage('acme/lib', 'https://github.com/acme/lib', maintainers: [self::maintainer('bob', 'bob-token')]);
        $picker = new MaintainerGitHubTokenPicker(new ArrayAdapter());

        $bob = $picker->pick($package, 'acme');
        self::assertNotNull($bob);
        $picker->recordRefusal($bob, GitHubTokenRefusal::RateLimited);

        self::assertSame('bob-token', $picker->pick($package, 'acme')?->token);
    }

    public function testSkipsThePackagistAccount(): void
    {
        $package = self::createPackage('acme/lib', 'https://github.com/acme/lib', maintainers: [
            self::maintainer('packagist', 'packagist-token', 1),
            self::maintainer('bob', 'bob-token'),
        ]);

        self::assertSame('bob-token', new MaintainerGitHubTokenPicker(new ArrayAdapter())->pick($package, 'acme')?->token);
    }

    private static function maintainer(string $username, ?string $githubToken, int $id = 100): User
    {
        $user = self::createUser($username, $username.'@example.org');
        $user->setGithubToken($githubToken);
        new \ReflectionProperty($user, 'id')->setValue($user, $id);

        return $user;
    }
}
