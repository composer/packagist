<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AboutControllerTest extends WebTestCase
{
    public function testPackagist()
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/about');
        $this->assertEquals('What is Packagist?', $crawler->filter('h2.title')->first()->text());
    }
}
