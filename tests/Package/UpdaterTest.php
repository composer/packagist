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
use Symfony\Component\Mailer\MailerInterface;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class UpdaterTest extends TestCase
{
    private IOInterface&MockObject $ioMock;
    private Config $config;
    private Package $package;
    private Updater $updater;
    private RepositoryInterface&MockObject $repositoryMock;
    private VcsDriverInterface&MockObject $driverMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->config = new Config();
        $this->package = new Package();
        $this->package->setName('test/pkg');
        (new \ReflectionProperty($this->package, 'repository'))->setValue($this->package, 'https://example.com/test/pkg');
        (new \ReflectionProperty($this->package, 'id'))->setValue($this->package, 1);

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
        $emMock->expects($this->any())->method('merge')->willReturnCallback(static function ($package) {
            return $package;
        });
        $emMock->expects($this->any())->method('persist')->willReturnCallback(static function ($object) {
            if ($reflProperty = new \ReflectionProperty($object, 'id')) {
                $reflProperty->setValue($object, random_int(0, 10000));
            }
        });

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

        $mailerMock = $this->createMock(MailerInterface::class);
        $routerMock = $this->createMock(UrlGeneratorInterface::class);

        $this->updater = new Updater($registryMock, $providerManagerMock, $versionIdCache, $mailerMock, 'foo@example.org', $routerMock);
    }

    public function tearDown(): void
    {
        parent::tearDown();
        unset($this->updater);
    }

    public function testUpdatesTheReadme(): void
    {
        $this->driverMock->expects($this->any())->method('getRootIdentifier')->willReturn('master');
        $this->driverMock->expects($this->any())->method('getComposerInformation')
                         ->willReturn(['readme' => 'README.md']);
        $this->driverMock->expects($this->once())->method('getFileContent')->with('README.md', 'master')
                         ->willReturn('This is the readme');

        $this->updater->update($this->ioMock, $this->config, $this->package, $this->repositoryMock);

        $this->assertStringContainsString('This is the readme', $this->package->getReadme());
    }

    public function testConvertsMarkdownForReadme(): void
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
    public function testNoUsefulTitlesAreRemovedForReadme(): void
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

    public function testSurroundsTextReadme(): void
    {
        $this->driverMock->expects($this->any())->method('getRootIdentifier')->willReturn('master');
        $this->driverMock->expects($this->any())->method('getComposerInformation')
                         ->willReturn(['readme' => 'README.txt']);
        $this->driverMock->expects($this->once())->method('getFileContent')->with('README.txt', 'master')
                         ->willReturn('This is the readme');

        $this->updater->update($this->ioMock, $this->config, $this->package, $this->repositoryMock);

        self::assertSame('<pre>This is the readme</pre>', $this->package->getReadme());
    }

    public function testUnderstandsDifferentFileNames(): void
    {
        $this->driverMock->expects($this->any())->method('getRootIdentifier')->willReturn('master');
        $this->driverMock->expects($this->any())->method('getComposerInformation')
                         ->willReturn(['readme' => 'liesmich']);
        $this->driverMock->expects($this->once())->method('getFileContent')->with('liesmich', 'master')
                         ->willReturn('This is the readme');

        $this->updater->update($this->ioMock, $this->config, $this->package, $this->repositoryMock);

        self::assertSame('<pre>This is the readme</pre>', $this->package->getReadme());
    }

    public function testReadmeParsing(): void
    {
        $readme = <<<'SOURCE'
<div id="readme" class="md" data-path="README.md"><article class="markdown-body entry-content container-lg" itemprop="text"><p dir="auto"><a target="_blank" rel="noopener noreferrer" href="docs/img/header.jpg"><img src="docs/img/header.jpg" alt="Fork CMS" style="max-width: 100%;"></a></p>
<p dir="auto"><a href="https://github.com/forkcms/forkcms/actions?query=workflow%3Arun-tests+branch%3Amaster"><img src="https://camo.githubusercontent.com/3e6124495dd6d2a2943f2bb8f9ddc1a021975e815a76c969d0e4ac2ee0c83044/68747470733a2f2f696d672e736869656c64732e696f2f6769746875622f776f726b666c6f772f7374617475732f666f726b636d732f666f726b636d732f72756e2d7465737473" alt="Build Status" data-canonical-src="https://img.shields.io/github/workflow/status/forkcms/forkcms/run-tests" style="max-width: 100%;"></a>
<a href="https://packagist.org/packages/forkcms/forkcms" rel="nofollow"><img src="https://camo.githubusercontent.com/079cfede4022aeaf86ef7121aee97d41c7fb2a4978313be826fb97ff97f9ea49/68747470733a2f2f706f7365722e707567782e6f72672f666f726b636d732f666f726b636d732f762f737461626c65" alt="Latest Stable Version" data-canonical-src="https://poser.pugx.org/forkcms/forkcms/v/stable" style="max-width: 100%;"></a>
<a href="https://packagist.org/packages/forkcms/forkcms" rel="nofollow"><img src="https://camo.githubusercontent.com/1688b5b9a70fd0d4b1617cabbaf2796581ee594959d7c8a133cfdc5adbeeb593/68747470733a2f2f706f7365722e707567782e6f72672f666f726b636d732f666f726b636d732f6c6963656e7365" alt="License" data-canonical-src="https://poser.pugx.org/forkcms/forkcms/license" style="max-width: 100%;"></a>
<a href="http://codecov.io/github/forkcms/forkcms?branch=master" rel="nofollow"><img src="https://camo.githubusercontent.com/c23678264bfbf2c33e3e3ec01cd98cd14f555dd089cd9179f9711bd9e0e922fc/68747470733a2f2f636f6465636f762e696f2f67682f666f726b636d732f666f726b636d732f6272616e63682f6d61737465722f67726170682f62616467652e7376673f746f6b656e3d61686a373068564f3239" alt="Code Coverage" data-canonical-src="https://codecov.io/gh/forkcms/forkcms/branch/master/graph/badge.svg?token=ahj70hVO29" style="max-width: 100%;"></a>
<a href="http://docs.fork-cms.com/" rel="nofollow"><img src="https://camo.githubusercontent.com/c3eb421d08c0bd1532c0202cb4f553ff980a137542649c5efb8070675aa7d8ae/68747470733a2f2f696d672e736869656c64732e696f2f62616467652f646f63732d6c61746573742d627269676874677265656e2e737667" alt="Documentation Status" data-canonical-src="https://img.shields.io/badge/docs-latest-brightgreen.svg" style="max-width: 100%;"></a>
<a href="https://huntr.dev" rel="nofollow"><img src="https://camo.githubusercontent.com/e4d94c2f9b165cf77f52242133dc5cec8ff52b03ca22794e2098d2a44665c6ff/68747470733a2f2f63646e2e68756e74722e6465762f68756e74725f73656375726974795f62616467652e737667" alt="huntr.dev | the place to protect open source" data-canonical-src="https://cdn.huntr.dev/huntr_security_badge.svg" style="max-width: 100%;"></a></p>
<div class="markdown-heading" dir="auto"><h2 class="heading-element" dir="auto">Installation</h2><a id="user-content-installation" class="anchor" aria-label="Permalink: Installation" href="#installation"><svg class="octicon octicon-link" viewBox="0 0 16 16" version="1.1" width="16" height="16" aria-hidden="true"><path d="m7.775 3.275 1.25-1.25a3.5 3.5 0 1 1 4.95 4.95l-2.5 2.5a3.5 3.5 0 0 1-4.95 0 .751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018 1.998 1.998 0 0 0 2.83 0l2.5-2.5a2.002 2.002 0 0 0-2.83-2.83l-1.25 1.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042Zm-4.69 9.64a1.998 1.998 0 0 0 2.83 0l1.25-1.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042l-1.25 1.25a3.5 3.5 0 1 1-4.95-4.95l2.5-2.5a3.5 3.5 0 0 1 4.95 0 .751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018 1.998 1.998 0 0 0-2.83 0l-2.5 2.5a1.998 1.998 0 0 0 0 2.83Z"></path></svg></a></div>
<ol dir="auto">
<li><g-emoji class="g-emoji" alias="warning">⚠️</g-emoji> Test Emoji Make sure you have <a href="https://getcomposer.org/" rel="nofollow">composer</a> installed.</li>
<li>Run <code>composer create-project forkcms/forkcms .</code> in your document root.</li>
<li>Browse to your website</li>
<li>Follow the steps on-screen</li>
<li>Have fun!</li>
</ol>
<div class="markdown-heading" dir="auto"><h3 class="heading-element" dir="auto">Dependencies</h3><a id="user-content-dependencies" class="anchor" aria-label="Permalink: Dependencies" href="#dependencies"><svg class="octicon octicon-link" viewBox="0 0 16 16" version="1.1" width="16" height="16" aria-hidden="true"><path d="m7.775 3.275 1.25-1.25a3.5 3.5 0 1 1 4.95 4.95l-2.5 2.5a3.5 3.5 0 0 1-4.95 0 .751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018 1.998 1.998 0 0 0 2.83 0l2.5-2.5a2.002 2.002 0 0 0-2.83-2.83l-1.25 1.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042Zm-4.69 9.64a1.998 1.998 0 0 0 2.83 0l1.25-1.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042l-1.25 1.25a3.5 3.5 0 1 1-4.95-4.95l2.5-2.5a3.5 3.5 0 0 1 4.95 0 .751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018 1.998 1.998 0 0 0-2.83 0l-2.5 2.5a1.998 1.998 0 0 0 0 2.83Z"></path></svg></a></div>
<p dir="auto"><strong>Remark</strong>: If you are using GIT instead of composer create-project or the zip-file from <a href="http://www.fork-cms.com" rel="nofollow">http://www.fork-cms.com</a>, you
should install our dependencies. The dependencies are handled by <a href="http://getcomposer.org/" rel="nofollow">composer</a></p>
<p dir="auto">To install the dependencies, you can run the command below in the document-root:</p>
<div class="snippet-clipboard-content notranslate position-relative overflow-auto" data-snippet-clipboard-copy-content="composer install -o"><pre class="notranslate"><code>composer install -o
</code></pre></div>
<div class="markdown-heading" dir="auto"><h2 class="heading-element" dir="auto">Security</h2><a id="user-content-security" class="anchor" aria-label="Permalink: Security" href="#security"><svg class="octicon octicon-link" viewBox="0 0 16 16" version="1.1" width="16" height="16" aria-hidden="true"><path d="m7.775 3.275 1.25-1.25a3.5 3.5 0 1 1 4.95 4.95l-2.5 2.5a3.5 3.5 0 0 1-4.95 0 .751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018 1.998 1.998 0 0 0 2.83 0l2.5-2.5a2.002 2.002 0 0 0-2.83-2.83l-1.25 1.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042Zm-4.69 9.64a1.998 1.998 0 0 0 2.83 0l1.25-1.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042l-1.25 1.25a3.5 3.5 0 1 1-4.95-4.95l2.5-2.5a3.5 3.5 0 0 1 4.95 0 .751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018 1.998 1.998 0 0 0-2.83 0l-2.5 2.5a1.998 1.998 0 0 0 0 2.83Z"></path></svg></a></div>
<p dir="auto">If you discover any security-related issues, please email <a href="mailto:core@fork-cms.com">core@fork-cms.com</a> instead of using the issue tracker.
HTML is allowed in translations because you sometimes need it. Any reports regarding this will not be accepted as a security issue. Owners of a website can narrow down who can add/edit translation strings using the group permissions.</p>
<div class="markdown-heading" dir="auto"><h2 class="heading-element" dir="auto">Bugs</h2><a id="user-content-bugs" class="anchor" aria-label="Permalink: Bugs" href="#bugs"><svg class="octicon octicon-link" viewBox="0 0 16 16" version="1.1" width="16" height="16" aria-hidden="true"><path d="m7.775 3.275 1.25-1.25a3.5 3.5 0 1 1 4.95 4.95l-2.5 2.5a3.5 3.5 0 0 1-4.95 0 .751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018 1.998 1.998 0 0 0 2.83 0l2.5-2.5a2.002 2.002 0 0 0-2.83-2.83l-1.25 1.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042Zm-4.69 9.64a1.998 1.998 0 0 0 2.83 0l1.25-1.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042l-1.25 1.25a3.5 3.5 0 1 1-4.95-4.95l2.5-2.5a3.5 3.5 0 0 1 4.95 0 .751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018 1.998 1.998 0 0 0-2.83 0l-2.5 2.5a1.998 1.998 0 0 0 0 2.83Z"></path></svg></a></div>
<p dir="auto">If you encounter any bugs, please create an issue on <a href="https://github.com/forkcms/forkcms/issues">Github</a>.
If you're stuck or would like to discuss Fork CMS: <a href="https://fork-cms.herokuapp.com" rel="nofollow"><img src="https://camo.githubusercontent.com/f21b09fbe2280a6ee4a1badd19dd95404a233c132c021239d80d07a5e44bc8ec/68747470733a2f2f696d6775722e636f6d2f7a5875765264772e706e67" alt="Join our Slack channel" data-canonical-src="https://imgur.com/zXuvRdw.png" style="max-width: 100%;"> Join our Slack Channel!</a></p>
<div class="markdown-heading" dir="auto"><h2 class="heading-element" dir="auto">Running the tests</h2><a id="user-content-running-the-tests" class="anchor" aria-label="Permalink: Running the tests" href="#running-the-tests"><svg class="octicon octicon-link" viewBox="0 0 16 16" version="1.1" width="16" height="16" aria-hidden="true"><path d="m7.775 3.275 1.25-1.25a3.5 3.5 0 1 1 4.95 4.95l-2.5 2.5a3.5 3.5 0 0 1-4.95 0 .751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018 1.998 1.998 0 0 0 2.83 0l2.5-2.5a2.002 2.002 0 0 0-2.83-2.83l-1.25 1.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042Zm-4.69 9.64a1.998 1.998 0 0 0 2.83 0l1.25-1.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042l-1.25 1.25a3.5 3.5 0 1 1-4.95-4.95l2.5-2.5a3.5 3.5 0 0 1 4.95 0 .751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018 1.998 1.998 0 0 0-2.83 0l-2.5 2.5a1.998 1.998 0 0 0 0 2.83Z"></path></svg></a></div>
<p dir="auto">We use phpunit as a test framework. It's installed when using composer install.
To be able to run them, make sure you have a database with the same credentials as
your normal database and with the name suffixed with _test.</p>
<p dir="auto">Because we support multiple php versions it gave some issues. Therefore we use the bridge from symfony.</p>
<p dir="auto">Running the tests:</p>
<div class="snippet-clipboard-content notranslate position-relative overflow-auto" data-snippet-clipboard-copy-content="composer test"><pre class="notranslate"><code>composer test
</code></pre></div>
<p dir="auto">Running only the unit, functional, or the installer tests</p>
<div class="snippet-clipboard-content notranslate position-relative overflow-auto" data-snippet-clipboard-copy-content=" composer test -- --testsuite=functional
 composer test -- --testsuite=unit
 composer test -- --testsuite=installer"><pre class="notranslate"><code> composer test -- --testsuite=functional
 composer test -- --testsuite=unit
 composer test -- --testsuite=installer
</code></pre></div>
<p dir="auto">If you want to run all the tests except the ones from the installer use</p>
<div class="snippet-clipboard-content notranslate position-relative overflow-auto" data-snippet-clipboard-copy-content="composer test -- --exclude-group=installer"><pre class="notranslate"><code>composer test -- --exclude-group=installer
</code></pre></div>
<div class="markdown-heading" dir="auto"><h2 class="heading-element" dir="auto">Styling the backend</h2><a id="user-content-styling-the-backend" class="anchor" aria-label="Permalink: Styling the backend" href="#styling-the-backend"><svg class="octicon octicon-link" viewBox="0 0 16 16" version="1.1" width="16" height="16" aria-hidden="true"><path d="m7.775 3.275 1.25-1.25a3.5 3.5 0 1 1 4.95 4.95l-2.5 2.5a3.5 3.5 0 0 1-4.95 0 .751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018 1.998 1.998 0 0 0 2.83 0l2.5-2.5a2.002 2.002 0 0 0-2.83-2.83l-1.25 1.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042Zm-4.69 9.64a1.998 1.998 0 0 0 2.83 0l1.25-1.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042l-1.25 1.25a3.5 3.5 0 1 1-4.95-4.95l2.5-2.5a3.5 3.5 0 0 1 4.95 0 .751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018 1.998 1.998 0 0 0-2.83 0l-2.5 2.5a1.998 1.998 0 0 0 0 2.83Z"></path></svg></a></div>
<p dir="auto">The backend uses <a href="http://www.getbootstrap.com" rel="nofollow">Bootstrap</a> in combination with Sass. To make changes, you should make
the changes into the scss-files, and regenerate the real css with <code>gulp build</code>.</p>
<div class="markdown-heading" dir="auto"><h2 class="heading-element" dir="auto">Yarn</h2><a id="user-content-yarn" class="anchor" aria-label="Permalink: Yarn" href="#yarn"><svg class="octicon octicon-link" viewBox="0 0 16 16" version="1.1" width="16" height="16" aria-hidden="true"><path d="m7.775 3.275 1.25-1.25a3.5 3.5 0 1 1 4.95 4.95l-2.5 2.5a3.5 3.5 0 0 1-4.95 0 .751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018 1.998 1.998 0 0 0 2.83 0l2.5-2.5a2.002 2.002 0 0 0-2.83-2.83l-1.25 1.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042Zm-4.69 9.64a1.998 1.998 0 0 0 2.83 0l1.25-1.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042l-1.25 1.25a3.5 3.5 0 1 1-4.95-4.95l2.5-2.5a3.5 3.5 0 0 1 4.95 0 .751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018 1.998 1.998 0 0 0-2.83 0l-2.5 2.5a1.998 1.998 0 0 0 0 2.83Z"></path></svg></a></div>
<p dir="auto">We use <a href="https://yarnpkg.com/" rel="nofollow">yarn</a> to install our dependencies. For now we have a <code>gulp</code>-script that moves everything to
the correct directories. So if you change the dependencies, make sure you run <code>gulp build</code>.</p>
<div class="markdown-heading" dir="auto"><h2 class="heading-element" dir="auto">Community</h2><a id="user-content-community" class="anchor" aria-label="Permalink: Community" href="#community"><svg class="octicon octicon-link" viewBox="0 0 16 16" version="1.1" width="16" height="16" aria-hidden="true"><path d="m7.775 3.275 1.25-1.25a3.5 3.5 0 1 1 4.95 4.95l-2.5 2.5a3.5 3.5 0 0 1-4.95 0 .751.751 0 0 1 .018-1.042.751.751 0 0 1 1.042-.018 1.998 1.998 0 0 0 2.83 0l2.5-2.5a2.002 2.002 0 0 0-2.83-2.83l-1.25 1.25a.751.751 0 0 1-1.042-.018.751.751 0 0 1-.018-1.042Zm-4.69 9.64a1.998 1.998 0 0 0 2.83 0l1.25-1.25a.751.751 0 0 1 1.042.018.751.751 0 0 1 .018 1.042l-1.25 1.25a3.5 3.5 0 1 1-4.95-4.95l2.5-2.5a3.5 3.5 0 0 1 4.95 0 .751.751 0 0 1-.018 1.042.751.751 0 0 1-1.042.018 1.998 1.998 0 0 0-2.83 0l-2.5 2.5a1.998 1.998 0 0 0 0 2.83Z"></path></svg></a></div>
<p dir="auto"><a href="https://fork-cms.herokuapp.com" rel="nofollow"><img src="https://camo.githubusercontent.com/f21b09fbe2280a6ee4a1badd19dd95404a233c132c021239d80d07a5e44bc8ec/68747470733a2f2f696d6775722e636f6d2f7a5875765264772e706e67" alt="Join our Slack channel" data-canonical-src="https://imgur.com/zXuvRdw.png" style="max-width: 100%;"> Join our Slack Channel!</a></p>
<p dir="auto"><em>The Fork CMS team</em></p>

</article></div>
SOURCE;

        $reflMethod = new \ReflectionMethod($this->updater, 'prepareReadme');
        $readme = $reflMethod->invoke($this->updater, $readme, 'github.com', 'foo', 'bar');

        self::assertSame(<<<'EXPECTED'
<p><a target="_blank" href="https://github.com/foo/bar/blob/HEAD/docs/img/header.jpg" rel="nofollow noindex noopener external ugc"><img src="https://raw.github.com/foo/bar/HEAD/docs/img/header.jpg" alt="Fork CMS"></a></p>
<p><a href="https://github.com/forkcms/forkcms/actions?query=workflow%3Arun-tests+branch%3Amaster" rel="nofollow noindex noopener external ugc"><img src="https://camo.githubusercontent.com/3e6124495dd6d2a2943f2bb8f9ddc1a021975e815a76c969d0e4ac2ee0c83044/68747470733a2f2f696d672e736869656c64732e696f2f6769746875622f776f726b666c6f772f7374617475732f666f726b636d732f666f726b636d732f72756e2d7465737473" alt="Build Status"></a>
<a href="https://packagist.org/packages/forkcms/forkcms" rel="nofollow noindex noopener external ugc"><img src="https://camo.githubusercontent.com/079cfede4022aeaf86ef7121aee97d41c7fb2a4978313be826fb97ff97f9ea49/68747470733a2f2f706f7365722e707567782e6f72672f666f726b636d732f666f726b636d732f762f737461626c65" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/forkcms/forkcms" rel="nofollow noindex noopener external ugc"><img src="https://camo.githubusercontent.com/1688b5b9a70fd0d4b1617cabbaf2796581ee594959d7c8a133cfdc5adbeeb593/68747470733a2f2f706f7365722e707567782e6f72672f666f726b636d732f666f726b636d732f6c6963656e7365" alt="License"></a>
<a href="http://codecov.io/github/forkcms/forkcms?branch=master" rel="nofollow noindex noopener external ugc"><img src="https://camo.githubusercontent.com/c23678264bfbf2c33e3e3ec01cd98cd14f555dd089cd9179f9711bd9e0e922fc/68747470733a2f2f636f6465636f762e696f2f67682f666f726b636d732f666f726b636d732f6272616e63682f6d61737465722f67726170682f62616467652e7376673f746f6b656e3d61686a373068564f3239" alt="Code Coverage"></a>
<a href="http://docs.fork-cms.com/" rel="nofollow noindex noopener external ugc"><img src="https://camo.githubusercontent.com/c3eb421d08c0bd1532c0202cb4f553ff980a137542649c5efb8070675aa7d8ae/68747470733a2f2f696d672e736869656c64732e696f2f62616467652f646f63732d6c61746573742d627269676874677265656e2e737667" alt="Documentation Status"></a>
<a href="https://huntr.dev" rel="nofollow noindex noopener external ugc"><img src="https://camo.githubusercontent.com/e4d94c2f9b165cf77f52242133dc5cec8ff52b03ca22794e2098d2a44665c6ff/68747470733a2f2f63646e2e68756e74722e6465762f68756e74725f73656375726974795f62616467652e737667" alt="huntr.dev | the place to protect open source"></a></p>
<h2 class="heading-element">Installation</h2><a id="user-content-installation" class="anchor" href="#user-content-installation" rel="nofollow noindex noopener external ugc"></a>
<ol>
<li>&#9888;&#65039; Test Emoji Make sure you have <a href="https://getcomposer.org/" rel="nofollow noindex noopener external ugc">composer</a> installed.</li>
<li>Run <code>composer create-project forkcms/forkcms .</code> in your document root.</li>
<li>Browse to your website</li>
<li>Follow the steps on-screen</li>
<li>Have fun!</li>
</ol>
<h3 class="heading-element">Dependencies</h3><a id="user-content-dependencies" class="anchor" href="#user-content-dependencies" rel="nofollow noindex noopener external ugc"></a>
<p><strong>Remark</strong>: If you are using GIT instead of composer create-project or the zip-file from <a href="http://www.fork-cms.com" rel="nofollow noindex noopener external ugc">http://www.fork-cms.com</a>, you
should install our dependencies. The dependencies are handled by <a href="http://getcomposer.org/" rel="nofollow noindex noopener external ugc">composer</a></p>
<p>To install the dependencies, you can run the command below in the document-root:</p>
<pre class="notranslate"><code>composer install -o
</code></pre>
<h2 class="heading-element">Security</h2><a id="user-content-security" class="anchor" href="#user-content-security" rel="nofollow noindex noopener external ugc"></a>
<p>If you discover any security-related issues, please email <a href="mailto:core@fork-cms.com" rel="nofollow noindex noopener external ugc">core@fork-cms.com</a> instead of using the issue tracker.
HTML is allowed in translations because you sometimes need it. Any reports regarding this will not be accepted as a security issue. Owners of a website can narrow down who can add/edit translation strings using the group permissions.</p>
<h2 class="heading-element">Bugs</h2><a id="user-content-bugs" class="anchor" href="#user-content-bugs" rel="nofollow noindex noopener external ugc"></a>
<p>If you encounter any bugs, please create an issue on <a href="https://github.com/forkcms/forkcms/issues" rel="nofollow noindex noopener external ugc">Github</a>.
If you're stuck or would like to discuss Fork CMS: <a href="https://fork-cms.herokuapp.com" rel="nofollow noindex noopener external ugc"><img src="https://camo.githubusercontent.com/f21b09fbe2280a6ee4a1badd19dd95404a233c132c021239d80d07a5e44bc8ec/68747470733a2f2f696d6775722e636f6d2f7a5875765264772e706e67" alt="Join our Slack channel"> Join our Slack Channel!</a></p>
<h2 class="heading-element">Running the tests</h2><a id="user-content-running-the-tests" class="anchor" href="#user-content-running-the-tests" rel="nofollow noindex noopener external ugc"></a>
<p>We use phpunit as a test framework. It's installed when using composer install.
To be able to run them, make sure you have a database with the same credentials as
your normal database and with the name suffixed with _test.</p>
<p>Because we support multiple php versions it gave some issues. Therefore we use the bridge from symfony.</p>
<p>Running the tests:</p>
<pre class="notranslate"><code>composer test
</code></pre>
<p>Running only the unit, functional, or the installer tests</p>
<pre class="notranslate"><code> composer test -- --testsuite=functional
 composer test -- --testsuite=unit
 composer test -- --testsuite=installer
</code></pre>
<p>If you want to run all the tests except the ones from the installer use</p>
<pre class="notranslate"><code>composer test -- --exclude-group=installer
</code></pre>
<h2 class="heading-element">Styling the backend</h2><a id="user-content-styling-the-backend" class="anchor" href="#user-content-styling-the-backend" rel="nofollow noindex noopener external ugc"></a>
<p>The backend uses <a href="http://www.getbootstrap.com" rel="nofollow noindex noopener external ugc">Bootstrap</a> in combination with Sass. To make changes, you should make
the changes into the scss-files, and regenerate the real css with <code>gulp build</code>.</p>
<h2 class="heading-element">Yarn</h2><a id="user-content-yarn" class="anchor" href="#user-content-yarn" rel="nofollow noindex noopener external ugc"></a>
<p>We use <a href="https://yarnpkg.com/" rel="nofollow noindex noopener external ugc">yarn</a> to install our dependencies. For now we have a <code>gulp</code>-script that moves everything to
the correct directories. So if you change the dependencies, make sure you run <code>gulp build</code>.</p>
<h2 class="heading-element">Community</h2><a id="user-content-community" class="anchor" href="#user-content-community" rel="nofollow noindex noopener external ugc"></a>
<p><a href="https://fork-cms.herokuapp.com" rel="nofollow noindex noopener external ugc"><img src="https://camo.githubusercontent.com/f21b09fbe2280a6ee4a1badd19dd95404a233c132c021239d80d07a5e44bc8ec/68747470733a2f2f696d6775722e636f6d2f7a5875765264772e706e67" alt="Join our Slack channel"> Join our Slack Channel!</a></p>
<p><em>The Fork CMS team</em></p>


EXPECTED
, $readme);
    }
}
