<?php

namespace Packagist\WebBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class WebControllerTest extends WebTestCase
{
    public function testHomepage()
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/');
        $this->assertEquals('Getting Started', $crawler->filter('.getting-started h1')->text());
    }
    
    public function testPackages()
    {
        $client = self::createClient();
        //we expect at least one package
        $crawler = $client->request('GET', '/packages/');
        $this->assertTrue($crawler->filter('.packages li')->count() > 0);
    }
    
    public function testPackage()
    {
        $client = self::createClient();
        //we expect package to be clickable and showing at least 'package' div
        $crawler = $client->request('GET', '/packages/');
        $link = $crawler->filter('.packages li h1 a')->first()->attr('href');
        
        $crawler = $client->request('GET', $link);
        $this->assertTrue($crawler->filter('.package')->count() > 0);
    }
}