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

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AboutControllerTest extends WebTestCase
{
    public function testPackagist(): void
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/about');
        static::assertResponseIsSuccessful();
        static::assertEquals('What is Packagist?', $crawler->filter('h2.title')->first()->text());
    }

    public function testComposerRedirect(): void
    {
        $client = self::createClient();

        $client->request('GET', '/about-composer');
        static::assertResponseRedirects('https://getcomposer.org/', 301);
    }
}
