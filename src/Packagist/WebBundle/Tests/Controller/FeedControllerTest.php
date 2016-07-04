<?php

namespace Packagist\WebBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FeedControllerTest extends WebTestCase
{
    /**
     * @param string      $feed
     * @param string      $format
     * @param string|null $vendor
     *
     * @dataProvider provideForFeed
     */
    public function testFeedAction($feed, $format, $vendor = null)
    {
        $client = self::createClient();

        $url = $client->getContainer()->get('router')->generate($feed, ['_format' => $format, 'vendor' => $vendor]);

        $crawler = $client->request('GET', $url);

        $this->assertEquals(200, $client->getResponse()->getStatusCode(), $client->getResponse()->getContent());
        $this->assertContains($format, $client->getResponse()->getContent());

        if ($vendor !== null) {
            $this->assertContains($vendor, $client->getResponse()->getContent());
        }
    }

    public function provideForFeed()
    {
        return [
            ['feed_packages', 'rss'],
            ['feed_packages', 'atom'],
            ['feed_releases', 'rss'],
            ['feed_releases', 'atom'],
            ['feed_vendor', 'rss', 'symfony'],
            ['feed_vendor', 'atom', 'symfony'],
        ];
    }
}
