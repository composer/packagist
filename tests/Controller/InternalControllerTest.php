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

use App\Tests\IntegrationTestCase;

class InternalControllerTest extends IntegrationTestCase
{
    private function sign(string $path, string $contents, int $filemtime): string
    {
        $secret = (string) static::getContainer()->getParameter('kernel.secret');

        return hash_hmac('sha256', \strlen($path).':'.$path.':'.\strlen($contents).':'.$contents.':'.$filemtime, $secret);
    }

    private function post(string $path, string $contents, int $filemtime, string $sig): void
    {
        $this->client->request(
            'POST',
            '/internal/update-metadata',
            ['path' => $path, 'contents' => $contents, 'filemtime' => $filemtime],
            [],
            ['HTTP_Internal-Signature' => $sig]
        );
    }

    public function testAcceptsValidSignedMetadataWrite(): void
    {
        $path = 'p2/test/internal-write-'.bin2hex(random_bytes(6)).'.json';
        $contents = '{"packages":{}}';
        $filemtime = 1700000000;
        $target = static::getContainer()->getParameter('kernel.cache_dir').'/composer-packages-build/'.$path.'.gz';

        $this->post($path, $contents, $filemtime, $this->sign($path, $contents, $filemtime));

        self::assertSame(202, $this->client->getResponse()->getStatusCode(), $this->client->getResponse()->getContent());
        self::assertFileExists($target);
        @unlink($target);
    }

    public function testRejectsPathTraversalEvenWithValidSignature(): void
    {
        $path = 'p2/../../../../tmp/packagist-traversal-test.json';
        $contents = '{"packages":{}}';
        $filemtime = 1700000000;

        $this->post($path, $contents, $filemtime, $this->sign($path, $contents, $filemtime));

        // access-denied redirects to login rather than writing the file
        self::assertSame(302, $this->client->getResponse()->getStatusCode());
        self::assertFileDoesNotExist(sys_get_temp_dir().'/packagist-traversal-test.json.gz');
    }

    public function testRejectsInvalidSignature(): void
    {
        $this->post('p2/test/pkg.json', '{"packages":{}}', 1700000000, 'deadbeef');

        self::assertSame(302, $this->client->getResponse()->getStatusCode());
    }
}
