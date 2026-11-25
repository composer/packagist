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

use App\Util\GitHubTokenRefusal;
use Composer\Downloader\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitHubTokenRefusalTest extends TestCase
{
    #[DataProvider('provideFailures')]
    public function testFromFailure(?GitHubTokenRefusal $expected, int $statusCode, array $headers = [], ?string $body = null): void
    {
        // Arrange
        $exception = new TransportException('test', $statusCode);
        $exception->setHeaders($headers);
        if (null !== $body) {
            $exception->setResponse($body);
        }

        // Act
        $result = GitHubTokenRefusal::fromFailure($exception);

        // Assert
        self::assertSame($expected, $result);
    }

    /**
     * @return iterable<string, array{?GitHubTokenRefusal, int, list<string>, ?string}>
     */
    public static function provideFailures(): iterable
    {
        yield 'rejected 401' => [
            GitHubTokenRefusal::Rejected,
            401,
            [],
            null,
        ];

        yield 'saml sso header' => [
            GitHubTokenRefusal::SamlSso,
            403,
            ['x-github-sso: required'],
            null,
        ];

        yield 'rate limited remaining zero' => [
            GitHubTokenRefusal::RateLimited,
            403,
            ['x-ratelimit-remaining: 0'],
            null,
        ];

        yield 'ip allow list in body' => [
            GitHubTokenRefusal::IpAllowList,
            403,
            [],
            'Access denied due to organization IP allow list',
        ];

        yield 'unknown failure' => [
            null,
            500,
            [],
            'internal error',
        ];

        yield '403 without matching signals' => [
            null,
            403,
            ['x-ratelimit-remaining: 10'],
            'forbidden',
        ];
    }

    public function testEnumValues(): void
    {
        // Arrange / Act / Assert
        self::assertSame('token rejected', GitHubTokenRefusal::Rejected->value);
        self::assertSame('token not authorized for the organization\'s SAML SSO', GitHubTokenRefusal::SamlSso->value);
        self::assertSame('rate limit exhausted', GitHubTokenRefusal::RateLimited->value);
        self::assertSame('organization IP allow list blocks the token', GitHubTokenRefusal::IpAllowList->value);
    }
}
