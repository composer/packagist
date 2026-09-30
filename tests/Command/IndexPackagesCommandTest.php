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

namespace App\Tests\Command;

use App\Command\IndexPackagesCommand;
use App\Entity\Package;
use App\Entity\ProvideLink;
use App\Entity\Tag;
use App\Entity\Version;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use App\Search\PackageIndex;
use App\Service\Locker;
use App\Tests\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Tester\CommandTester;

class IndexPackagesCommandTest extends IntegrationTestCase
{
    /**
     * Tags and providers are fetched once per 50-package slice rather than once per package, so the
     * thing worth pinning is that each package still gets its own rows back and not its neighbour's.
     */
    public function testEachPackageInASliceGetsItsOwnTagsAndProviders(): void
    {
        $logger = $this->packageWith('acme/logger', ['log-writer', 'log writer'], ['psr/log-implementation']);
        $client = $this->packageWith('acme/client', ['http'], ['psr/http-client-implementation', 'psr/request-factory-implementation']);
        $bare = $this->packageWith('acme/bare', [], []);

        $records = $this->runIndexer();

        self::assertSame(['log writer'], $records['acme/logger']['tags'], 'tags are deduped after normalizing, so log-writer and log writer collapse');
        self::assertSame(['http'], $records['acme/client']['tags']);
        self::assertSame([], $records['acme/bare']['tags'], 'a package with no tags gets an empty list, not a missing key');

        self::assertArrayHasKey('virtual:psr/log-implementation', $records);
        self::assertArrayHasKey('virtual:psr/http-client-implementation', $records);
        self::assertArrayHasKey('virtual:psr/request-factory-implementation', $records);

        // the ids prove the rows were keyed back to the right package rather than merged
        self::assertSame($logger->getId(), $records['acme/logger']['id']);
        self::assertSame($client->getId(), $records['acme/client']['id']);
        self::assertSame($bare->getId(), $records['acme/bare']['id']);
    }

    public function testProvidersOnlyComeFromTheDefaultBranch(): void
    {
        // getProviders() filters on pv.development, and batching must not lose that: a tagged
        // release's provide entries would otherwise leak virtual packages back into the index.
        $this->packageWith('acme/tagged', [], ['psr/log-implementation'], development: false);

        $records = $this->runIndexer();

        self::assertArrayHasKey('acme/tagged', $records, 'the package itself is still indexed');
        self::assertArrayNotHasKey('virtual:psr/log-implementation', $records, 'a tagged release must not contribute virtual packages');
    }

    /**
     * @param list<string> $tags
     * @param list<string> $provides
     */
    private function packageWith(string $name, array $tags, array $provides, bool $development = true): Package
    {
        $package = self::createPackage($name, 'https://github.com/'.$name);

        $version = new Version();
        $version->setPackage($package);
        $version->setName($name);
        $version->setVersion('dev-main');
        $version->setNormalizedVersion('dev-main');
        $version->setDevelopment($development);
        $version->setLicense([]);
        $version->setAutoload([]);
        $package->getVersions()->add($version);

        $stored = [$package, $version];
        foreach ($tags as $tag) {
            $version->addTag(new Tag($tag));
        }
        foreach ($version->getTags() as $tag) {
            $stored[] = $tag;
        }

        foreach ($provides as $provided) {
            $link = new ProvideLink();
            $link->setVersion($version);
            $link->setPackageName($provided);
            $link->setPackageVersion('*');
            $stored[] = $link;
        }

        $this->store(...$stored);

        return $package;
    }

    private static function redisClient(): Client
    {
        $redis = self::getContainer()->get('snc_redis.default');
        self::assertInstanceOf(Client::class, $redis);

        return $redis;
    }

    /**
     * @return array<string, array<string, mixed>> the saved records, keyed by objectID
     */
    private function runIndexer(): array
    {
        $index = new class implements PackageIndex {
            /** @var list<array<string, mixed>> */
            public array $saved = [];

            public function search(array $searchParams): array
            {
                throw new \LogicException('not expected');
            }

            public function browse(array $browseParams): iterable
            {
                throw new \LogicException('not expected');
            }

            public function saveRecords(array $records): void
            {
                foreach ($records as $record) {
                    $this->saved[] = $record;
                }
            }

            public function deleteRecord(string $objectId): void
            {
            }

            public function clear(): void
            {
            }

            public function updateSettings(array $settings): void
            {
            }
        };

        $command = new IndexPackagesCommand(
            $index,
            self::getService(Locker::class),
            self::getService(ManagerRegistry::class),
            self::redisClient(),
            self::getService(DownloadManager::class),
            self::getService(FavoriteManager::class),
            (string) self::getContainer()->getParameter('kernel.cache_dir'),
            self::getService(\Graze\DogStatsD\Client::class),
        );
        // the command reads the -v option, which only exists once the application definition is merged
        new Application()->addCommand($command);

        $tester = new CommandTester($command);
        $tester->execute([]);
        $tester->assertCommandIsSuccessful();

        $byObjectId = [];
        foreach ($index->saved as $record) {
            $byObjectId[$record['objectID']] = $record;
        }

        return $byObjectId;
    }
}
