<?php

namespace Packagist\WebBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FeedControllerTest extends WebTestCase
{
    /**
     * @param string $feed
     * @param string $format
     * @param string|null $filter
     *
     * @dataProvider provideForFeed
     */
    public function testFeedAction($feed, $format, $filter = null)
    {
        $client = self::createClient();

        $url = $client->getContainer()->get('router')->generate($feed, array('_format' => $format, 'filter' => $filter));

        $crawler = $client->request('GET', $url);

        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertContains($format, $client->getResponse()->getContent());

        if ($filter !== null) {
            $this->assertContains($filter, $client->getResponse()->getContent());
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
