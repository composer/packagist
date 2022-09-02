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

use App\Entity\Dependent;
use App\Entity\DependentRepository;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\IO\NullIO;
use Composer\Package\CompletePackage;
use Composer\Repository\RepositoryInterface;
use Composer\Repository\Vcs\GitDriver;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Repository\VcsRepository;
use Doctrine\Bundle\DoctrineBundle\Registry;
use Doctrine\ORM\EntityManager;
use Doctrine\DBAL\Connection;
use App\Entity\Package;
use App\Entity\Version;
use App\Package\Updater;
use App\Entity\VersionRepository;
use App\Model\ProviderManager;
use App\Model\VersionIdCache;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class UpdaterTest extends TestCase
{
    private IOInterface $ioMock;

    private Config $config;

    private Package $package;

    private Updater $updater;
    /** @var RepositoryInterface&MockObject */
    private $repositoryMock;
    /** @var VcsDriverInterface&MockObject */
    private $driverMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Config();
        $this->package = new Package();
        $this->package->setName('test/pkg');
        $this->package->setRepository('https://example.com/test/pkg');
        $reflProp = new \ReflectionProperty(Package::class, 'id');
        $reflProp->setAccessible(true);
        $reflProp->setValue($this->package, 1);

        $this->ioMock = $this->createMock(NullIO::class);
        $this->repositoryMock = $this->createMock(VcsRepository::class);
        $registryMock = $this->createMock(Registry::class);
        $providerManagerMock = $this->createMock(ProviderManager::class);
        $emMock = $this->createMock(EntityManager::class);
        $connectionMock = $this->createMock(Connection::class);
        $package = new CompletePackage('test/pkg', '1.0.0.0', '1.0.0');
        $this->driverMock = $this->createMock(GitDriver::class);
        $versionRepoMock = $this->createMock(VersionRepository::class);
        $dependentRepoMock = $this->createMock(DependentRepository::class);

        $versionRepoMock->expects($this->any())->method('getVersionMetadataForUpdate')->willReturn([]);
        $emMock->expects($this->any())->method('getConnection')->willReturn($connectionMock);
        $emMock->expects($this->any())->method('merge')->will($this->returnCallback(static function ($package) {
            return $package;
        }));
        $emMock->expects($this->any())->method('persist')->will($this->returnCallback(static function ($object) {
            if ($reflProperty = new \ReflectionProperty($object, 'id')) {
                $reflProperty->setAccessible(true);
                $reflProperty->setValue($object, random_int(0, 10000));
            }
        }));

        $registryMock->method('getManager')->willReturn($emMock);
        $registryMock->method('getRepository')->willReturnMap([
            [Version::class, null, $versionRepoMock],
            [Dependent::class, null, $dependentRepoMock],
        ]);
        $this->repositoryMock->expects($this->any())->method('getPackages')->willReturn([
            $package,
        ]);
        $this->repositoryMock->expects($this->any())->method('getDriver')->willReturn($this->driverMock);

        $versionIdCache = $this->createMock(VersionIdCache::class);

        $this->updater = new Updater($registryMock, $providerManagerMock, $versionIdCache);
    }

    public function testUpdatesTheReadme()
    {
        $this->driverMock->expects($this->any())->method('getRootIdentifier')->willReturn('master');
        $this->driverMock->expects($this->any())->method('getComposerInformation')
                         ->willReturn(['readme' => 'README.md']);
        $this->driverMock->expects($this->once())->method('getFileContent')->with('README.md', 'master')
                         ->willReturn('This is the readme');

        $this->updater->update($this->ioMock, $this->config, $this->package, $this->repositoryMock);

        $this->assertStringContainsString('This is the readme', $this->package->getReadme());
    }

    public function testConvertsMarkdownForReadme()
    {
        $readme = <<<EOR
# some package name

Why you should use this package:
 - it is easy to use
 - no overhead
 - minimal requirements

EOR;
        $readmeHtml = <<<EOR

<p>Why you should use this package:</p>
<ul>
<li>it is easy to use</li>
<li>no overhead</li>
<li>minimal requirements</li>
</ul>

EOR;

        $this->driverMock->expects($this->any())->method('getRootIdentifier')->willReturn('master');
        $this->driverMock->expects($this->any())->method('getComposerInformation')
                         ->willReturn(['readme' => 'README.md']);
        $this->driverMock->expects($this->once())->method('getFileContent')->with('README.md', 'master')
                         ->willReturn($readme);

        $this->updater->update($this->ioMock, $this->config, $this->package, $this->repositoryMock);

        self::assertSame($readmeHtml, $this->package->getReadme());
    }

    /**
     * When <h1> or <h2> titles are not the first element of the README contents,
     * they should not be removed.
     */
    public function testNoUsefulTitlesAreRemovedForReadme()
    {
        $readme = <<<EOR
Lorem ipsum dolor sit amet.

# some title

EOR;
        $readmeHtml = <<<EOR
<p>Lorem ipsum dolor sit amet.</p>
<h1>some title</h1>

EOR;

        $this->driverMock->expects($this->any())->method('getRootIdentifier')->willReturn('master');
        $this->driverMock->expects($this->any())->method('getComposerInformation')
                         ->willReturn(['readme' => 'README.md']);
        $this->driverMock->expects($this->once())->method('getFileContent')->with('README.md', 'master')
                         ->willReturn($readme);

        $this->updater->update($this->ioMock, $this->config, $this->package, $this->repositoryMock);

        self::assertSame($readmeHtml, $this->package->getReadme());
    }

    public function testSurrondsTextReadme()
    {
        $this->driverMock->expects($this->any())->method('getRootIdentifier')->willReturn('master');
        $this->driverMock->expects($this->any())->method('getComposerInformation')
                         ->willReturn(['readme' => 'README.txt']);
        $this->driverMock->expects($this->once())->method('getFileContent')->with('README.txt', 'master')
                         ->willReturn('This is the readme');

        $this->updater->update($this->ioMock, $this->config, $this->package, $this->repositoryMock);

        self::assertSame('<pre>This is the readme</pre>', $this->package->getReadme());
    }

    public function testUnderstandsDifferentFileNames()
    {
        $this->driverMock->expects($this->any())->method('getRootIdentifier')->willReturn('master');
        $this->driverMock->expects($this->any())->method('getComposerInformation')
                         ->willReturn(['readme' => 'liesmich']);
        $this->driverMock->expects($this->once())->method('getFileContent')->with('liesmich', 'master')
                         ->willReturn('This is the readme');

        $this->updater->update($this->ioMock, $this->config, $this->package, $this->repositoryMock);

        self::assertSame('<pre>This is the readme</pre>', $this->package->getReadme());
    }
}
