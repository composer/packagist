<?php

namespace Packagist\WebBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class AboutControllerTest extends WebTestCase
{
    public function testPackagist()
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/about');
        $this->assertEquals('What is Packagist?', $crawler->filter('.box h1')->first()->text());
    }
    
    public function testComposer()
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/about-composer');
        $this->assertEquals('What is Composer?', $crawler->filter('.box h1')->first()->text());
    }
}