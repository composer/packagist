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

namespace App\Tests;

use App\Tests\Fixtures\Fixtures;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

class IntegrationTestCase extends WebTestCase
{
    use Fixtures;

    protected KernelBrowser $client;

    protected function setUp(): void
    {
        $this->client = self::createClient();
        $this->client->disableReboot(); // prevent reboot to keep the transaction

        // The DB is rolled back per test but Redis is not, so cached values keyed by package name
        // would leak into later tests that reuse a name.
        self::flushRedisCache();

        static::getService(Connection::class)->beginTransaction();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        static::getService(Connection::class)->rollBack();

        parent::tearDown();
    }

    protected static function redisCache(): Client
    {
        $client = static::getContainer()->get('snc_redis.cache');
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }

    /**
     * REDIS_CACHE_URL defaults to ${REDIS_URL} outside .env.test and a real env var beats the
     * dotenv file, so assert the separate DB this flush relies on instead of trusting it. The
     * comparison goes through a throwaway client rather than snc_redis.default, which must stay
     * uninitialised here so tests can still replace it. Predis connects lazily, so this is free.
     */
    private static function flushRedisCache(): void
    {
        $cache = self::redisCache();
        $default = new Client((string) ($_ENV['REDIS_URL'] ?? ''));

        if (self::redisTarget($cache) === self::redisTarget($default)) {
            throw new \RuntimeException(
                'Refusing to flush '.self::redisTarget($cache).': REDIS_CACHE_URL resolves to the same '
                .'Redis database as REDIS_URL, point it at a dedicated one as .env.test does.'
            );
        }

        $cache->flushdb();
    }

    private static function redisTarget(Client $client): string
    {
        $params = $client->getConnection()->getParameters();

        return $params->host.':'.$params->port.'/'.($params->database ?? 0);
    }

    public static function getEM(): EntityManagerInterface
    {
        return static::getService(ManagerRegistry::class)->getManager();
    }

    /**
     * @template T
     * @param class-string<T> $className
     * @return T
     */
    protected static function getService(string $className)
    {
        $service = static::getContainer()->get($className);
        assert($service instanceof $className);

        return $service;
    }

    protected function assertFormError(string $message, string $formName, Crawler $crawler): void
    {
        $formCrawler = $crawler->filter(\sprintf('[name="%s"]', $formName));
        // Match on the rendered text rather than a CSS `:contains("…")` selector, which breaks when
        // the message itself contains double quotes (e.g. '"composer" is a reserved name…').
        // `.alert-danger` covers whole-form errors, `.invalid-feedback` covers per-field errors
        // rendered by the Bootstrap 5 form theme.
        $matching = $formCrawler->filter('.alert-danger, .invalid-feedback')->reduce(
            static fn (Crawler $node): bool => str_contains($node->text(), $message),
        );
        $this->assertCount(
            1,
            $matching,
            $formCrawler->html()."\nShould find an error message within the form: '$message'",
        );
    }

    /**
     * @param object|array<object> $objects
     */
    protected function store(array|object ...$objects): void
    {
        $em = $this->getEM();
        foreach ($objects as $obj) {
            if (\is_array($obj)) {
                foreach ($obj as $obj2) {
                    $em->persist($obj2);
                }
            } else {
                $em->persist($obj);
            }
        }

        $em->flush();
    }
}
