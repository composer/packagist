<?php

namespace App\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FeedControllerTest extends WebTestCase
{
    /**
     * @param string $feed
     * @param string $format
     * @param string|null $vendor
     *
     * @dataProvider provideForFeed
     */
    public function testFeedAction($feed, $format, $vendor = null)
    {
        $client = self::createClient();

        $url = $client->getContainer()->get('router')->generate($feed, array('_format' => $format, 'vendor' => $vendor));

        $crawler = $client->request('GET', $url);

        $this->assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->assertStringContainsString($format, $client->getResponse()->getContent());

        if ($vendor !== null) {
            $this->assertStringContainsString($vendor, $client->getResponse()->getContent());
        }

    }


    public function provideForFeed()
    {
        return array(
            array('feed_packages', 'rss'),
            array('feed_packages', 'atom'),
            array('feed_releases', 'rss'),
            array('feed_releases', 'atom'),
            array('feed_vendor', 'rss', 'symfony'),
            array('feed_vendor', 'atom', 'symfony'),
        );
    }

}
