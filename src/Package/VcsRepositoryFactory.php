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
use Composer\Downloader\TransportException;
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
    /** git repositories are read over smart HTTP in PHP, never with the git binary */
    public const array DRIVERS = [
        'github' => Vcs\GitHubDriver::class,
        'gitlab' => Vcs\GitLabDriver::class,
        'git-bitbucket' => Vcs\GitBitbucketDriver::class,
        'forgejo' => Vcs\ForgejoDriver::class,
        'git' => Vcs\HttpGitDriver::class,
    ];

    public static function createConfig(): Config
    {
        $config = Factory::createConfig();
        $config->merge(['config' => ['github-protocols' => ['https']]]);

        return $config;
    }

    /**
     * @param array<string, mixed> $options
     * @param ?list<string>        $knownReferences commits the caller already has data for, which makes git repositories fetch all others in a few batched requests
     */
    public static function create(string $url, IOInterface $io, Config $config, HttpDownloader $httpDownloader, ?VersionCacheInterface $versionCache = null, array $options = [], ?array $knownReferences = null): VcsRepository
    {
        // no driver runs the git binary anymore, this keeps it to https should one start doing so again
        Platform::putEnv('GIT_ALLOW_PROTOCOL', 'https');

        self::assertPublicHost($url);

        // a GitHub API failure fails the update instead of switching to a git clone, which lacks the repo data and readme
        $repoConfig = ['url' => $url, 'allow-git-fallback' => false, 'options' => $options];
        if ($knownReferences !== null) {
            $repoConfig['known-references'] = $knownReferences;
        }

        return new VcsRepository($repoConfig, $io, $config, $httpDownloader, null, null, self::DRIVERS, $versionCache);
    }

    /**
     * Returns the API error that made GitHubDriver attempt the (disabled) git fallback, or $e itself
     */
    public static function unwrapDisabledGitFallback(\Throwable $e): \Throwable
    {
        if ($e instanceof \RuntimeException && $e->getMessage() === 'Fallback to git driver disabled' && $e->getPrevious() instanceof TransportException) {
            return $e->getPrevious();
        }

        return $e;
    }

    /**
     * Refuses obviously internal hosts before any driver connects, with a failure the updater recognises
     *
     * The HttpDownloader's prevent_ip_access_callable still guards every connection, redirects included.
     *
     * @throws PrivateRepositoryHostException if the host resolves to a private or reserved address
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
                throw new PrivateRepositoryHostException('The repository host '.$host.' resolves to a private or reserved IP address, only publicly reachable repositories are supported');
            }
        }
    }
}
