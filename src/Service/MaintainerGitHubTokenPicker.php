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

namespace App\Service;

use App\Entity\Package;
use Psr\Cache\CacheItemPoolInterface;
use Psr\SimpleCache\CacheInterface;
use Symfony\Component\Cache\Psr16Cache;

/**
 * Picks the maintainer token a GitHub package update authenticates with
 *
 * Tokens are not checked up front, LoggingHttpDownloader retries with the Packagist token when GitHub refuses one.
 */
class MaintainerGitHubTokenPicker
{
    private const int IP_ALLOW_LIST_SKIP_TTL = 7 * 86400;

    private CacheInterface $cache;

    public function __construct(CacheItemPoolInterface $cache)
    {
        $this->cache = new Psr16Cache($cache);
    }

    public function pick(Package $package, string $owner): ?MaintainerGitHubToken
    {
        foreach ($package->getMaintainers() as $maintainer) {
            if ($maintainer->getId() === 1) {
                continue;
            }
            $token = $maintainer->getGithubToken();
            if (null === $token || '' === $token) {
                continue;
            }

            $candidate = new MaintainerGitHubToken($token, $maintainer->getUsernameCanonical(), strtolower($owner));
            if ('1' === $this->cache->get(self::allowListKey($candidate))) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * An org IP allow list applies to its members' tokens, another token may still read a public repo
     */
    public function skipForIpAllowList(MaintainerGitHubToken $token): void
    {
        $this->cache->set(self::allowListKey($token), '1', self::IP_ALLOW_LIST_SKIP_TTL);
    }

    private static function allowListKey(MaintainerGitHubToken $token): string
    {
        return 'github_ip_allow_list_'.$token->username.'_'.$token->owner;
    }
}
