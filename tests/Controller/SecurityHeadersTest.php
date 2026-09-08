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

class SecurityHeadersTest extends IntegrationTestCase
{
    public function testHtmlPagesCarryTheDocumentSecurityHeaders(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.com/test/pkg');
        $this->store($package);

        $this->client->request('GET', '/packages/test/pkg');

        self::assertResponseIsSuccessful();
        self::assertResponseHeaderSame('X-Frame-Options', 'DENY');
        self::assertResponseHasHeader('Content-Security-Policy');
    }

    public function testJsonApiResponsesSkipTheDocumentSecurityHeaders(): void
    {
        // Neither header does anything for a non-document response, and these endpoints carry
        // millions of responses per day, so they must not pay for ~600 bytes of CSP each.
        $this->client->request('GET', '/packages/list.json');

        self::assertResponseIsSuccessful();
        self::assertResponseNotHasHeader('X-Frame-Options');
        self::assertResponseNotHasHeader('Content-Security-Policy');
    }
}
