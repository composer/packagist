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

use App\Service\CdnClient;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\NoPrivateNetworkHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class CdnClientTest extends TestCase
{
    public function testSingleFileUploadsAreCappedButBulkUploadsKeepTheClientDefault(): void
    {
        // literal public IPs as NoPrivateNetworkHttpClient resolves hostnames before the mock sees them
        /** @var list<string> $maxDurations */
        $maxDurations = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$maxDurations): MockResponse {
            $maxDurations[] = $method.' '.$options['max_duration'];

            return $method === 'PUT'
                ? new MockResponse('', ['http_code' => 201])
                : new MockResponse('', ['response_headers' => ['Last-Modified' => 'Thu, 15 Oct 2026 19:00:00 GMT']]);
        });
        $client = new CdnClient(new NoPrivateNetworkHttpClient($http), 'https://1.1.1.1/zone/', 'https://1.0.0.1/', 'key', 'cdn-key', 'packagist.example', new NullLogger());

        self::assertSame(strtotime('2026-10-15 19:00:00 UTC') * 10000, $client->uploadMetadata('p2/acme/package.json', '{}'));
        $client->sendUploadMetadataRequest('p2/acme/package.json', '{}')->getStatusCode();

        self::assertSame(['PUT 10', 'GET 10', 'PUT 0'], $maxDurations);
    }
}
