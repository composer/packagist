<?php declare(strict_types=1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class FeedControllerTest extends ControllerTestCase
{
    #[DataProvider('provideForFeed')]
    public function testFeedAction(string $feed, string $format, ?string $vendor = null): void
    {
        $url = static::getContainer()->get(UrlGeneratorInterface::class)->generate($feed, ['_format' => $format, 'vendor' => $vendor]);

        $this->client->request('GET', $url);

        $response = $this->client->getResponse();
        $this->assertEquals(200, $response->getStatusCode(), $response->getContent());
        $this->assertStringContainsString($format, $response->getContent());

        if ($vendor !== null) {
            $this->assertStringContainsString($vendor, $response->getContent());
        }
    }

    public static function provideForFeed(): array
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
