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

use App\Service\ReplicaClient;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

class ReplicaClientTest extends TestCase
{
    public function testSendsGzippedRawBodyWithMetadataInHeaders(): void
    {
        $contents = '{"packages":{"acme/pkg":[]}}';
        /** @var list<array{url: string, headers: array<string, list<string>>, body: string, max_redirects: int}> $requests */
        $requests = [];
        $http = new MockHttpClient(static function (string $method, string $url, array $options) use (&$requests): MockResponse {
            $requests[] = ['url' => $url, 'headers' => $options['normalized_headers'], 'body' => $options['body'], 'max_redirects' => $options['max_redirects']];

            return new MockResponse('OK', ['http_code' => 202]);
        });

        new ReplicaClient($http, ['10.0.0.1', '10.0.0.2'], 'secret')->uploadMetadata('p2/acme/pkg.json', $contents, 1700000000);

        self::assertCount(2, $requests);
        self::assertSame('http://10.0.0.1/internal/update-metadata', $requests[0]['url']);
        self::assertSame('http://10.0.0.2/internal/update-metadata', $requests[1]['url']);
        foreach ($requests as $request) {
            self::assertSame($contents, gzdecode($request['body']));
            self::assertSame(['Metadata-Path: p2/acme/pkg.json'], $request['headers']['metadata-path']);
            self::assertSame(['Metadata-Filemtime: 1700000000'], $request['headers']['metadata-filemtime']);
            self::assertSame(['Content-Type: application/gzip'], $request['headers']['content-type']);
            self::assertSame(['Internal-Signature: '.ReplicaClient::sign('p2/acme/pkg.json', $request['body'], 1700000000, 'secret')], $request['headers']['internal-signature']);
            self::assertSame(0, $request['max_redirects']);
        }
    }

    public function testNonAcceptedResponseThrowsWithStatusAndReplica(): void
    {
        $http = new MockHttpClient([
            new MockResponse('OK', ['http_code' => 202]),
            new MockResponse('Invalid signature', ['http_code' => 403]),
        ]);

        $this->expectException(TransportException::class);
        $this->expectExceptionCode(403);
        $this->expectExceptionMessageMatches('{^Invalid response code 403 from replica 10\.0\.0\.2 for p2/acme/pkg\.json}');

        new ReplicaClient($http, ['10.0.0.1', '10.0.0.2'], 'secret')->uploadMetadata('p2/acme/pkg.json', '{}', 1700000000);
    }
}
