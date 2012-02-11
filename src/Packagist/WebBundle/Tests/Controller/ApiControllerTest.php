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
        
        $client->request('GET', '/api/github.json');
        $this->assertEquals(405, $client->getResponse()->getStatusCode(), 'GET method should not be allowed for GitHub Post-Receive URL');

        $doctrine = $client->getContainer()->get('doctrine');
        $em = $doctrine->getEntityManager();
        $userRepo = $doctrine->getRepository('PackagistWebBundle:User');
        $testUser = new User();
        $testUser->setUsername('ApiControllerTest');
        $payload = json_encode(array('repository' => array('url' => 'git://github.com/composer/composer',)));

        $client->request('POST', '/api/github.json?username='.$testUser->getUsername().'&apiToken=BAD'.$testUser->getApiToken(), array('payload' => $payload,));
        $this->assertEquals(403, $client->getResponse()->getStatusCode(), 'POST method should return 403 "Forbidden" if invalid API Token is sent');

        $client->request('POST', '/api/github.json?username=BAD'.$testUser->getUsername().'&apiToken='.$testUser->getApiToken(), array('payload' => $payload,));
        $this->assertEquals(403, $client->getResponse()->getStatusCode(), 'POST method should return 403 "Forbidden" if invalid API Token is sent');
    }
}