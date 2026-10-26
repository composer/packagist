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

use App\Package\VcsRepositoryFactory;
use Composer\IO\NullIO;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;
use Composer\Util\ProcessExecutor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class VcsRepositoryFactoryTest extends TestCase
{
    protected function tearDown(): void
    {
        Platform::clearEnv('GIT_ALLOW_PROTOCOL');
    }

    public function testGitHubIsOnlyReachedOverHttps(): void
    {
        self::assertSame(['https'], VcsRepositoryFactory::createConfig()->get('github-protocols'));
    }

    public function testGitIsLimitedToHttpTransports(): void
    {
        $this->createRepository();

        self::assertSame('https:http', Platform::getEnv('GIT_ALLOW_PROTOCOL'));
    }

    #[DataProvider('provideDisallowedUrls')]
    public function testGitRefusesOtherTransportsBeforeConnecting(string $url): void
    {
        $this->createRepository();

        $process = new ProcessExecutor();
        self::assertNotSame(0, $process->execute(['git', 'ls-remote', '--', $url], $output, sys_get_temp_dir()));
        self::assertStringContainsString("transport '".strstr($url, ':', true)."' not allowed", $process->getErrorOutput());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideDisallowedUrls(): iterable
    {
        yield 'git' => ['git://packagist.invalid/acme/foo.git'];
        yield 'ssh' => ['ssh://git@packagist.invalid/acme/foo.git'];
        yield 'ftp' => ['ftp://packagist.invalid/acme/foo.git'];
        yield 'file' => ['file:///tmp/acme-foo.git'];
    }

    private function createRepository(): void
    {
        $io = new NullIO();
        $config = VcsRepositoryFactory::createConfig();
        VcsRepositoryFactory::create('https://packagist.invalid/acme/foo.git', $io, $config, new HttpDownloader($io, $config));
    }
}
