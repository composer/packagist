<?php

namespace Packagist\WebBundle\Tests\Controller;

use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

class FeedControllerTest extends WebTestCase
{
    /**
     * @param $feed
     * @param $format
     *
     * @dataProvider provideForFeed
     */
    public function testFeedAction($feed, $format, $filter = null)
    {
        $client = self::createClient();

        $filterExtra = ($filter !== null)? ".$filter":'';

        $crawler = $client->request('GET', "/feed/$feed$filterExtra.$format");

        var_dump($client->getResponse()->getContent());
        $this->assertEquals(200, $client->getResponse()->getStatusCode());
        $this->assertContains($format, $client->getResponse()->getContent());

        if ($filter !== null) {
            $this->assertContains($filter, $client->getResponse()->getContent());
        }
    }


    public function provideForFeed()
    {
        return array(
            array('latest', 'rss'),
            array('latest', 'atom'),
            array('newest', 'rss'),
            array('newest', 'atom'),
            array('vendor', 'rss', 'symfony'),
            array('vendor', 'atom', 'symfony'),
        );
    }

}
