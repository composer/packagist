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

namespace App\Tests\Package;

use App\Entity\FilterListEntry;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Entity\SecurityAdvisory;
use App\Entity\Version;
use App\FilterList\Dump\FilterListDumperProvider;
use App\FilterList\FilterLists;
use App\FilterList\RemoteFilterListEntry;
use App\Model\ProviderManager;
use App\Package\V2Dumper;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\Service\CdnClient;
use App\Service\ReplicaClient;
use App\Tests\IntegrationTestCase;
use Composer\Semver\VersionParser;
use Doctrine\Persistence\ManagerRegistry;
use Graze\DogStatsD\Client as StatsDClient;
use Monolog\Logger;
use Predis\Client as RedisClient;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class V2DumperTest extends IntegrationTestCase
{
    private string $webDir;
    private string $buildDir;
    private V2Dumper $dumper;

    protected function setUp(): void
    {
        parent::setUp();

        $this->webDir = sys_get_temp_dir().'/v2dumper_web_'.uniqid('', true);
        $this->buildDir = sys_get_temp_dir().'/v2dumper_build_'.uniqid('', true);
        mkdir($this->webDir, 0o777, true);
        mkdir($this->buildDir, 0o777, true);

        // Return a filemtime that does NOT end with '0000' so the Redis counter branch is skipped
        $cdnClient = $this->createStub(CdnClient::class);
        $cdnClient->method('uploadMetadata')->willReturn(16062106090001);
        $cdnClient->method('purgeMetadataCache')->willReturn(true);

        $filterListDumperProvider = static::getContainer()->get(FilterListDumperProvider::class);

        $this->dumper = new V2Dumper(
            static::getContainer()->get(ManagerRegistry::class),
            new Filesystem(),
            $this->createStub(RedisClient::class),
            $this->webDir,
            $this->buildDir,
            $this->createStub(StatsDClient::class),
            $this->createStub(ProviderManager::class),
            new Logger('test'),
            $cdnClient,
            $this->createStub(ReplicaClient::class),
            static::getContainer()->get(UrlGeneratorInterface::class),
            new MockHttpClient(),
            $filterListDumperProvider,
        );
    }

    protected function tearDown(): void
    {
        parent::tearDown();

        new Filesystem()->remove($this->webDir);
        new Filesystem()->remove($this->buildDir);
    }

    public function testDumpPackageMetadata(): void
    {
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $version = $this->createVersion($package, '1.0.0');
        $devVersion = $this->createVersion($package, 'dev-main');
        $this->store($package, $version, $devVersion);

        $this->dumper->dump([$package->getId()], force: true);

        $releaseFile = $this->buildDir.'/p2/acme/package.json';
        $this->assertFileExists($releaseFile);

        $data = json_decode((string) file_get_contents($releaseFile), true);
        $this->assertSame('composer/2.0', $data['minified']);
        $this->assertNotEmpty($data['packages']['acme/package']);
        $this->assertSame([], $data['security-advisories']);
        $this->assertArrayNotHasKey('filter', $data);

        $devFile = $this->buildDir.'/p2/acme/package~dev.json';
        $this->assertFileExists($devFile);

        $devData = json_decode((string) file_get_contents($devFile), true);
        $this->assertArrayNotHasKey('security-advisories', $devData);
    }

    public function testDumpSecurityAdvisories(): void
    {
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $version = $this->createVersion($package, '1.0.0');
        $this->store($package, $version);

        $remote = new RemoteSecurityAdvisory(
            'GHSA-test-1234-5678',
            'Test advisory',
            'acme/package',
            '>=1.0.0,<2.0.0',
            'https://example.com/advisory',
            null,
            new \DateTimeImmutable('2024-01-01'),
            'https://packagist.org',
            [],
            'test-source',
            null,
        );
        $advisory = new SecurityAdvisory($remote, 'test-source');
        $this->store($advisory);

        $this->dumper->dump([$package->getId()], force: true);

        $releaseFile = $this->buildDir.'/p2/acme/package.json';
        $this->assertFileExists($releaseFile);

        $data = json_decode((string) file_get_contents($releaseFile), true);
        $this->assertCount(1, $data['security-advisories']);
        $entry = $data['security-advisories'][0];
        $this->assertNotEmpty($entry['advisoryId']);
        $this->assertSame('>=1.0.0,<2.0.0', $entry['affectedVersions']);
    }

    public function testDumpFilterEntries(): void
    {
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $version = $this->createVersion($package, '1.0.0');
        $this->store($package, $version);

        $remote = new RemoteFilterListEntry(
            'acme/package',
            '1.0.0',
            FilterLists::AIKIDO_MALWARE,
            null,
            'malware',
        );
        $filterEntry = new FilterListEntry($remote);
        $this->store($filterEntry);

        $this->dumper->dump([$package->getId()], force: true);

        $releaseFile = $this->buildDir.'/p2/acme/package.json';
        $this->assertFileExists($releaseFile);

        $data = json_decode((string) file_get_contents($releaseFile), true);
        $this->assertArrayHasKey('filter', $data);
        $this->assertArrayHasKey('aikido-malware', $data['filter']);
        $filterList = $data['filter']['aikido-malware'];
        $this->assertNotEmpty($filterList);
        $this->assertSame('1.0.0', $filterList[0]['constraint']);
        $this->assertSame('malware', $filterList[0]['reason']);
    }

    public function testSpamFrozenPackageIsSkipped(): void
    {
        $package = self::createPackage('acme/package', 'https://example.com/acme/package');
        $package->freeze(PackageFreezeReason::Spam);
        $version = $this->createVersion($package, '1.0.0');
        $this->store($package, $version);

        $this->dumper->dump([$package->getId()], force: true);

        $releaseFile = $this->buildDir.'/p2/acme/package.json';
        $this->assertFileDoesNotExist($releaseFile);
    }

    private function createVersion(Package $package, string $version): Version
    {
        $versionParser = new VersionParser();
        $isDev = VersionParser::parseStability($version) === 'dev';
        $v = new Version();
        $v->setName($package->getName());
        $v->setVersion($version);
        $v->setNormalizedVersion($isDev ? $version : $versionParser->normalize($version));
        $v->setLicense(['MIT']);
        $v->setAutoload([]);
        $v->setDevelopment($isDev);
        $v->setPackage($package);
        $package->getVersions()->add($v);
        $v->setReleasedAt(new \DateTimeImmutable());
        $v->setUpdatedAt(new \DateTimeImmutable());

        return $v;
    }
}
