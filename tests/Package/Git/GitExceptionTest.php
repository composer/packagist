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

namespace App\Tests\Package\Git;

use App\Package\Git\ProtocolException;
use App\Package\Git\RepositoryNotFoundException;
use App\Package\Git\RepositoryUnavailableException;
use Composer\Downloader\TransportException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class GitExceptionTest extends TestCase
{
    /**
     * VcsRepository aborts the update on a TransportException with a 401/403/429/5xx code and skips the version on any other exception
     */
    #[DataProvider('provideStatusCodes')]
    public function testCodesAbortTheUpdate(TransportException $exception, int $expectedCode, ?int $expectedStatusCode): void
    {
        self::assertSame($expectedCode, $exception->getCode());
        self::assertSame($expectedStatusCode, $exception->getStatusCode());
        self::assertTrue(\in_array($exception->getCode(), [401, 403, 429], true) || $exception->getCode() >= 500);
    }

    /**
     * @return iterable<string, array{TransportException, int, ?int}>
     */
    public static function provideStatusCodes(): iterable
    {
        yield 'no http status' => [new ProtocolException('invalid data'), 500, null];
        yield '503' => [new RepositoryUnavailableException('down', 503), 503, 503];
        yield '429' => [new RepositoryUnavailableException('throttled', 429), 429, 429];
        yield '401' => [new RepositoryNotFoundException('private', 401), 401, 401];
        yield '404 would be skipped as a missing composer.json' => [new RepositoryNotFoundException('gone', 404), 500, 404];
        yield '400' => [new RepositoryNotFoundException('bad request', 400), 500, 400];
    }
}
