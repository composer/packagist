<?php

namespace Packagist\WebBundle\Tests\Controller;

use Packagist\WebBundle\Entity\User;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class ApiControllerTest extends WebTestCase
{
    public function testPackages()
    {
        $client = self::createClient();

        $client->request('GET', '/packages.json');
        $this->assertTrue(count(json_decode($client->getResponse()->getContent())) > 0);
    }

    public function testGithubFailsCorrectly()
    {
        $client = self::createClient();
        
        $client->request('GET', '/api/github');
        $this->assertEquals(405, $client->getResponse()->getStatusCode(), 'GET method should not be allowed for GitHub Post-Receive URL');

        $payload = json_encode(array('repository' => array('url' => 'git://github.com/composer/composer',)));
        $client->request('POST', '/api/github?username=INVALID_USER&apiToken=INVALID_TOKEN', array('payload' => $payload,));
        $this->assertEquals(403, $client->getResponse()->getStatusCode(), 'POST method should return 403 "Forbidden" if invalid username and API Token are sent');
    }
}