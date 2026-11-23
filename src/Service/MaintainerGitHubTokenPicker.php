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
use App\Util\GitHubTokenRefusal;
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
    private const int SAML_SSO_SKIP_TTL = 86400;
    private const int REJECTED_SKIP_TTL = 86400;

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
            if ($this->cache->has(self::allowListKey($candidate)) || $this->cache->has(self::samlSsoKey($candidate)) || $this->cache->has(self::rejectedKey($candidate))) {
                continue;
            }

            return $candidate;
        }

        return null;
    }

    /**
     * Skips a token GitHub refused for a while, so the next update tries the next maintainer's token
     */
    public function recordRefusal(MaintainerGitHubToken $token, GitHubTokenRefusal $refusal): void
    {
        match ($refusal) {
            // org IP allow lists and SAML SSO apply to the org's members, their token may still read other repos
            GitHubTokenRefusal::IpAllowList => $this->cache->set(self::allowListKey($token), '1', self::IP_ALLOW_LIST_SKIP_TTL),
            GitHubTokenRefusal::SamlSso => $this->cache->set(self::samlSsoKey($token), '1', self::SAML_SSO_SKIP_TTL),
            GitHubTokenRefusal::Rejected => $this->cache->set(self::rejectedKey($token), '1', self::REJECTED_SKIP_TTL),
            GitHubTokenRefusal::RateLimited => null,
        };
    }

    private static function allowListKey(MaintainerGitHubToken $token): string
    {
        return 'github_ip_allow_list_'.$token->username.'_'.$token->owner;
    }

    private static function samlSsoKey(MaintainerGitHubToken $token): string
    {
        return 'github_saml_sso_'.$token->username.'_'.$token->owner;
    }

    // keyed on the token so linking a new one takes effect right away
    private static function rejectedKey(MaintainerGitHubToken $token): string
    {
        return 'github_token_rejected_'.hash('xxh128', $token->token);
    }
}
