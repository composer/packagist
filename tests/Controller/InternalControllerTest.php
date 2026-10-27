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

namespace App\Tests\Controller;

use App\Service\ReplicaClient;
use App\Tests\IntegrationTestCase;

class InternalControllerTest extends IntegrationTestCase
{
    private function post(string $path, string $gzipped, int $filemtime, ?string $sig = null): void
    {
        $sig ??= ReplicaClient::sign($path, $gzipped, $filemtime, (string) static::getContainer()->getParameter('kernel.secret'));

        $this->client->request(
            'POST',
            '/internal/update-metadata',
            [],
            [],
            [
                'CONTENT_TYPE' => 'application/gzip',
                'HTTP_Internal-Signature' => $sig,
                'HTTP_Metadata-Path' => $path,
                'HTTP_Metadata-Filemtime' => (string) $filemtime,
            ],
            $gzipped,
        );
    }

    public function testAcceptsValidSignedMetadataWrite(): void
    {
        $path = 'p2/test/internal-write-'.bin2hex(random_bytes(6)).'.json';
        $gzipped = (string) gzencode('{"packages":{}}');
        $target = static::getContainer()->getParameter('kernel.cache_dir').'/composer-packages-build/'.$path.'.gz';

        $this->post($path, $gzipped, 1700000000);

        self::assertSame(202, $this->client->getResponse()->getStatusCode(), (string) $this->client->getResponse()->getContent());
        self::assertSame($gzipped, file_get_contents($target));
        self::assertSame(1700000000, filemtime($target));
        @unlink($target);
    }

    public function testRejectsPathTraversalEvenWithValidSignature(): void
    {
        $this->post('p2/../../../../tmp/packagist-traversal-test.json', (string) gzencode('{"packages":{}}'), 1700000000);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertFileDoesNotExist(sys_get_temp_dir().'/packagist-traversal-test.json.gz');
    }

    public function testRejectsInvalidSignature(): void
    {
        $this->post('p2/test/pkg.json', (string) gzencode('{"packages":{}}'), 1700000000, 'deadbeef');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testRejectsSignatureForDifferentFilemtime(): void
    {
        $gzipped = (string) gzencode('{"packages":{}}');
        $sig = ReplicaClient::sign('p2/test/pkg.json', $gzipped, 1700000000, (string) static::getContainer()->getParameter('kernel.secret'));

        $this->post('p2/test/pkg.json', $gzipped, 1700000001, $sig);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }
}
