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

namespace App\Package;

use Composer\Config;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Repository\VcsRepository;
use Composer\Repository\VersionCacheInterface;
use Composer\Util\HttpDownloader;
use Composer\Util\Platform;
use Symfony\Component\HttpFoundation\IpUtils;

/**
 * Single place to set up Composer VCS repositories, so submission and updates use the same hardening.
 */
final class VcsRepositoryFactory
{
    public const array DRIVERS = [
        'github' => 'Composer\Repository\Vcs\GitHubDriver',
        'gitlab' => 'Composer\Repository\Vcs\GitLabDriver',
        'git-bitbucket' => 'Composer\Repository\Vcs\GitBitbucketDriver',
        'forgejo' => 'Composer\Repository\Vcs\ForgejoDriver',
        'git' => 'Composer\Repository\Vcs\GitDriver',
    ];

    public static function createConfig(): Config
    {
        $config = Factory::createConfig();
        $config->merge(['config' => ['github-protocols' => ['https']]]);

        return $config;
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function create(string $url, IOInterface $io, Config $config, HttpDownloader $httpDownloader, ?VersionCacheInterface $versionCache = null, array $options = []): VcsRepository
    {
        // git otherwise allows every transport but ext::, e.g. ftp:// or any installed git-remote-* helper
        Platform::putEnv('GIT_ALLOW_PROTOCOL', 'https:http');

        self::assertPublicHost($url);

        return new VcsRepository(['url' => $url, 'options' => $options], $io, $config, $httpDownloader, null, null, self::DRIVERS, $versionCache);
    }

    /**
     * git connects on its own, bypassing the prevent_ip_access_callable of our HttpDownloader
     *
     * This does not cover DNS rebinding or redirects, it only keeps obviously internal hosts away from git.
     *
     * @throws \RuntimeException if the host resolves to a private or reserved address
     */
    private static function assertPublicHost(string $url): void
    {
        $host = parse_url($url, \PHP_URL_HOST);
        if (!\is_string($host) || '' === $host) {
            throw new \RuntimeException('Could not determine the host of '.$url);
        }

        $host = trim($host, '[]');
        if (false !== filter_var($host, \FILTER_VALIDATE_IP, \FILTER_FLAG_IPV6)) {
            self::assertPublicIps($host, [$host]);

            return;
        }

        // gethostbynamel goes through the system resolver like git does, so /etc/hosts and numeric forms like 2130706433 are covered
        // unresolvable hosts are left for git to fail on, so the usual unreachable host handling applies
        self::assertPublicIps($host, gethostbynamel($host) ?: []);
        self::assertPublicIps($host, array_column(@dns_get_record($host, \DNS_AAAA) ?: [], 'ipv6'));
    }

    /**
     * @param list<string> $ips
     */
    private static function assertPublicIps(string $host, array $ips): void
    {
        foreach ($ips as $ip) {
            if (IpUtils::isPrivateIp($ip)) {
                throw new \RuntimeException('The repository host '.$host.' resolves to a private or reserved IP address, only publicly reachable repositories are supported');
            }
        }
    }
}
