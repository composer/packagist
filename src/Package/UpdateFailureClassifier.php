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

use App\Package\Git\GitException;
use App\Package\Git\RepositoryNotFoundException;
use App\Package\Git\RepositoryUnavailableException;
use Composer\Downloader\TransportException;
use Composer\Pcre\Preg;
use Composer\Util\Url;

/**
 * Detects update failures caused by a repository that is gone or a host that is unreachable
 */
final class UpdateFailureClassifier
{
    private const int MAX_DESCRIPTION_LENGTH = 1000;
    public const string GITHUB_IP_ALLOW_LIST = 'github_api_ip_allow_list';

    public static function classify(\Throwable $e): ?UpdateFailureVerdict
    {
        if ($e instanceof PrivateRepositoryHostException) {
            // a single DNS lookup from the worker, a sinkholed or briefly misconfigured domain must not freeze right away
            return new UpdateFailureVerdict(UpdateFailureKind::Unreachable, 'private_host', escalatesToGone: true);
        }

        if ($e instanceof GitException) {
            return self::classifyGitException($e);
        }

        $msg = $e->getMessage();

        if ($e instanceof TransportException) {
            $status = $e->getStatusCode();

            if ($status === 404 && str_contains($msg, 'https://gitlab.com/api/v4/projects/') && str_contains($msg, '404 Project Not Found')) {
                return self::gone('gitlab_api_404');
            }
            if ($status === 404 && Preg::isMatch('{https://api.bitbucket.org/2.0/repositories/[^/]+/.+?\?fields=-project}i', $msg)) {
                return self::gone('bitbucket_api_404');
            }
            if ($status === 409 && Preg::isMatch('{^The "https://api\.github\.com/repos/[^/]+/[^/]+?/git/refs/heads\?per_page=100" file could not be downloaded \(HTTP/2 409 \)}', $msg)) {
                return self::gone('github_api_empty_repository');
            }
            if (Preg::isMatch('{^The "https://api\.github\.com/repos/[^/]+/[^/]+?" file could not be downloaded}', $msg)) {
                if (\in_array($status, [404, 451], true) && str_contains($msg, '" file could not be downloaded (HTTP/2 '.$status.' )')) {
                    return self::gone('github_api_'.$status);
                }
                if ($status === 403 && self::isGitHubAccessBlockedResponse((string) $e->getResponse())) {
                    return self::gone('github_api_access_blocked');
                }
                if ($status === 403 && self::isGitHubIpAllowListResponse((string) $e->getResponse())) {
                    // depends on the worker IP and the token's org membership, so it may clear up without the repo changing
                    return new UpdateFailureVerdict(UpdateFailureKind::Unreachable, self::GITHUB_IP_ALLOW_LIST, escalatesToGone: true);
                }
            }
        }

        if (!$e instanceof \RuntimeException) {
            return null;
        }

        if (str_contains($msg, 'remote: Repository not found')) {
            return self::gone('git_remote_repository_not_found');
        }
        if (str_contains($msg, 'git@gitlab.com') && (str_contains($msg, 'Please make sure you have the correct access rights') || str_contains($msg, "transport 'ssh' not allowed"))) {
            // gitlab denies access for 404s, ssh being disallowed means we got here after an API 401/404
            return self::gone('gitlab_git_no_access');
        }
        if (str_contains($msg, '@gitlab.com/') && str_contains($msg, 'You are not allowed to download code from this project')) {
            return self::gone('gitlab_git_download_forbidden');
        }
        if (Preg::isMatch('{git@(?:ssh\.)?bitbucket\.org}', $msg) && (str_contains($msg, 'Please make sure you have the correct access rights') || str_contains($msg, "transport 'ssh' not allowed"))) {
            // bitbucket denies access for 404s, ssh being disallowed means we got here once https failed
            return self::gone('bitbucket_git_no_access');
        }
        if (str_contains($msg, '@github.com/') && str_contains($msg, ' Please ask the owner to check their account')) {
            return self::gone('github_git_account_disabled');
        }
        if (str_contains($msg, '@github.com/') && str_contains($msg, ' Your account is suspended.')) {
            return self::gone('github_git_account_suspended');
        }
        if (str_contains($msg, '@github.com/') && str_contains($msg, 'Access to this repository has been disabled by GitHub staff due to excessive resource use.')) {
            return self::gone('github_git_repository_disabled');
        }
        if (str_contains($msg, '@github.com/') && str_contains($msg, 'remote: Write access to repository not granted.') && str_contains($msg, 'The requested URL returned error: 403')) {
            // github answers a 403 when the repo does not exist (or is private?)
            return self::gone('github_git_403');
        }
        if (Preg::isMatch('{fatal: repository \'[^\']+\' not found\n}i', $msg)) {
            return self::gone('git_repository_not_found');
        }
        if (Preg::isMatch('{fatal: Authentication failed}i', $msg)) {
            return self::gone('git_authentication_failed');
        }
        if (Preg::isMatch('{Driver could not be established for package}i', $msg)) {
            // every driver rejected the url, for a self-hosted git that is any git ls-remote failure, transient ones included
            return new UpdateFailureVerdict(UpdateFailureKind::Unreachable, 'no_driver', escalatesToGone: true);
        }
        if (
            Preg::isMatch('{fatal: could not read Username for \'[^\']+\': No such device or address\n}i', $msg)
            || Preg::isMatch('{fatal: could not read Username for \'[^\']+\': terminal prompts disabled}i', $msg)
            || Preg::isMatch('{fatal: unable to access \'[^\']+\': Could not resolve host: }i', $msg)
            || Preg::isMatch('{fatal: unable to access \'[^\']+\': The requested URL returned error: 503}i', $msg)
            || Preg::isMatch('{fatal: unable to access \'[^\']+\': server certificate verification failed}i', $msg)
            || Preg::isMatch('{Can\'t connect to host \'[^\']+\': Connection (timed out|refused)}i', $msg)
            || Preg::isMatch('{Failed to connect to [\w.-]+ port \d+(?: after \d+ ms)?: Connection (timed out|refused)}i', $msg)
            || Preg::isMatch('{Failed to connect to [\w.-]+ port \d+(?: after \d+ ms)?: No route to host}i', $msg)
            || Preg::isMatch('{Failed to connect to [\w.-]+ port \d+(?: after \d+ ms)?: Couldn\'t connect to server}i', $msg)
            || Preg::isMatch('{SSL: certificate subject name \([\w.-]+\) does not match target host name \'[\w.-]+\'}i', $msg)
            || Preg::isMatch('{gnutls_handshake\(\) failed: The server name sent was not recognized}i', $msg)
        ) {
            return new UpdateFailureVerdict(UpdateFailureKind::Unreachable, 'host_unreachable');
        }

        return null;
    }

    /**
     * Admin-only audit text explaining a verdict, the job holding the full output gets purged
     */
    public static function describe(UpdateFailureVerdict $verdict, \Throwable $e): string
    {
        $description = $verdict->code.': '.$e::class.': '.Url::sanitize(trim($e->getMessage()));

        return mb_strimwidth($description, 0, self::MAX_DESCRIPTION_LENGTH, '…');
    }

    /**
     * GitHub answers 403 for repositories blocked by staff, e.g. for terms of service violations
     */
    public static function isGitHubAccessBlockedResponse(string $response): bool
    {
        return str_contains($response, 'Repository access blocked');
    }

    public static function isGitHubIpAllowListResponse(string $response): bool
    {
        return str_contains($response, 'has an IP allow list enabled');
    }

    /**
     * Failures of HttpGitDriver, which reads repositories hosted outside of the API-backed hosts
     */
    private static function classifyGitException(GitException $e): ?UpdateFailureVerdict
    {
        if ($e instanceof RepositoryNotFoundException) {
            if (\in_array($e->getStatusCode(), [404, 410], true)) {
                return self::gone('git_http_not_found');
            }

            // an auth prompt or a login page may be a repository made private by accident
            return new UpdateFailureVerdict(UpdateFailureKind::Unreachable, 'git_http_not_public', escalatesToGone: true);
        }
        if ($e instanceof RepositoryUnavailableException) {
            return new UpdateFailureVerdict(UpdateFailureKind::Unreachable, 'git_http_unavailable');
        }

        // unsupported servers and protocol errors are reported as a failed update
        return null;
    }

    private static function gone(string $code): UpdateFailureVerdict
    {
        return new UpdateFailureVerdict(UpdateFailureKind::Gone, $code);
    }
}
