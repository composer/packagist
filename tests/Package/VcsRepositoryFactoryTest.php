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
use Composer\Repository\VcsRepository;
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

    #[DataProvider('providePrivateHostUrls')]
    public function testPrivateHostsAreRefused(string $url): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('resolves to a private or reserved IP address');

        $this->createRepository($url);
    }

    /**
     * @return iterable<array{string}>
     */
    public static function providePrivateHostUrls(): iterable
    {
        yield 'loopback' => ['https://127.0.0.1/acme/foo.git'];
        yield 'localhost via /etc/hosts' => ['https://localhost/acme/foo.git'];
        yield 'decimal loopback' => ['https://2130706433/acme/foo.git'];
        yield 'rfc1918 with port' => ['https://10.1.2.3:8443/acme/foo.git'];
        yield 'cloud metadata' => ['http://169.254.169.254/latest/meta-data'];
        yield 'ipv6 loopback' => ['https://[::1]/acme/foo.git'];
        yield 'ipv6 unique local' => ['https://[fd00::1]/acme/foo.git'];
        yield 'ipv4-mapped ipv6' => ['https://[::ffff:127.0.0.1]/acme/foo.git'];
    }

    public function testPublicHostsAreAccepted(): void
    {
        self::assertSame('https://8.8.8.8/acme/foo.git', $this->createRepository('https://8.8.8.8/acme/foo.git')->getRepoConfig()['url']);
    }

    public function testUrlsWithoutHostAreRefused(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Could not determine the host');

        $this->createRepository('https:///acme/foo.git');
    }

    // public IP literal so no DNS lookup happens
    private function createRepository(string $url = 'https://8.8.8.8/acme/foo.git'): VcsRepository
    {
        $io = new NullIO();
        $config = VcsRepositoryFactory::createConfig();

        return VcsRepositoryFactory::create($url, $io, $config, new HttpDownloader($io, $config));
    }
}
