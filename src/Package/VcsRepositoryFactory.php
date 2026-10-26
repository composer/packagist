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
        'hg' => 'Composer\Repository\Vcs\HgDriver',
        'svn' => 'Composer\Repository\Vcs\SvnDriver',
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

        return new VcsRepository(['url' => $url, 'options' => $options], $io, $config, $httpDownloader, null, null, self::DRIVERS, $versionCache);
    }
}
