<?php

namespace Packagist\WebBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FeedControllerTest extends WebTestCase
{
    public function testLatest()
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/feed/latest.rss');
        $this->assertContains('rss', $client->getResponse()->getContent());
    }

    public function testLatestAtom()
    {
        $client = self::createClient();

        $crawler = $client->request('GET', '/feed/latest.atom');
        $this->assertContains('Atom', $client->getResponse()->getContent());
    }

}
