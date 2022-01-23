<?php

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
