<?php

namespace App\Tests\Package;

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
use App\Entity\AuthorRepository;
use App\Model\ProviderManager;
use PHPUnit\Framework\TestCase;
use PHPUnit_Framework_MockObject_MockObject;

class UpdaterTest extends TestCase
{
    /** @var IOInterface */
    private $ioMock;
    /** @var Config */
    private $config;
    /** @var Package */
    private $package;
    /** @var Updater */
    private $updater;
    /** @var RepositoryInterface|PHPUnit_Framework_MockObject_MockObject */
    private $repositoryMock;
    /** @var VcsDriverInterface|PHPUnit_Framework_MockObject_MockObject */
    private $driverMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config  = new Config();
        $this->package = new Package();

        $this->ioMock         = $this->getMockBuilder(NullIO::class)->disableOriginalConstructor()->getMock();
        $this->repositoryMock = $this->getMockBuilder(VcsRepository::class)->disableOriginalConstructor()->getMock();
        $registryMock         = $this->getMockBuilder(Registry::class)->disableOriginalConstructor()->getMock();
        $providerManagerMock  = $this->getMockBuilder(ProviderManager::class)->disableOriginalConstructor()->getMock();
        $emMock               = $this->getMockBuilder(EntityManager::class)->disableOriginalConstructor()->getMock();
        $connectionMock       = $this->getMockBuilder(Connection::class)->disableOriginalConstructor()->getMock();
        $packageMock          = $this->getMockBuilder(CompletePackage::class)->disableOriginalConstructor()->getMock();
        $this->driverMock     = $this->getMockBuilder(GitDriver::class)->disableOriginalConstructor()->getMock();
        $versionRepoMock      = $this->getMockBuilder(VersionRepository::class)->disableOriginalConstructor()->getMock();
        $authorRepoMock      = $this->getMockBuilder(AuthorRepository::class)->disableOriginalConstructor()->getMock();

        $versionRepoMock->expects($this->any())->method('getVersionMetadataForUpdate')->willReturn(array());
        $emMock->expects($this->any())->method('getConnection')->willReturn($connectionMock);
        $emMock->expects($this->any())->method('merge')->will($this->returnCallback(function ($package) { return $package; }));

        $registryMock->expects($this->any())->method('getManager')->willReturn($emMock);
        $registryMock->expects($this->at(1))->method('getRepository')->with(Version::class)->willReturn($versionRepoMock);
        $this->repositoryMock->expects($this->any())->method('getPackages')->willReturn([
            $packageMock
        ]);
        $this->repositoryMock->expects($this->any())->method('getDriver')->willReturn($this->driverMock);
        $packageMock->expects($this->any())->method('getRequires')->willReturn([]);
        $packageMock->expects($this->any())->method('getConflicts')->willReturn([]);
        $packageMock->expects($this->any())->method('getProvides')->willReturn([]);
        $packageMock->expects($this->any())->method('getReplaces')->willReturn([]);
        $packageMock->expects($this->any())->method('getDevRequires')->willReturn([]);
        $packageMock->expects($this->any())->method('isDefaultBranch')->willReturn(false);

        $this->updater = new Updater($registryMock, $providerManagerMock);
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
<ul><li>it is easy to use</li>
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
