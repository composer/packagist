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
}