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

class AboutControllerTest extends ControllerTestCase
{
    public function testPackagist(): void
    {
        $crawler = $this->client->request('GET', '/about');
        static::assertResponseIsSuccessful();
        static::assertEquals('What is Packagist?', $crawler->filter('h2.title')->first()->text());
    }

    public function testComposerRedirect(): void
    {
        $this->client->request('GET', '/about-composer');
        static::assertResponseRedirects('https://getcomposer.org/', 301);
    }
}
