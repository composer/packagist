<?php

namespace Packagist\WebBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiControllerTest extends WebTestCase
{
    public function testPackages()
    {
        $client = self::createClient();

        $client->request('GET', '/packages.json');
        $this->assertTrue(count(json_decode($client->getResponse()->getContent())) > 0);
    }
}