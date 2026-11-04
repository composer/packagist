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

use Composer\Downloader\TransportException;
use Composer\Pcre\Preg;
use Composer\Util\HttpDownloader;

/**
 * Asks the GitHub, GitLab or Bitbucket API whether a repository still exists, to confirm or refute a failed update
 */
class RepositoryApiChecker
{
    private const string GITHUB_REFERENCE_URL = 'https://api.github.com/repos/composer/composer/git/refs/heads';
    private const string GITLAB_REFERENCE_URL = 'https://gitlab.com/api/v4/projects/packagist%2Fvcs-repository-test';
    private const string BITBUCKET_REFERENCE_URL = 'https://api.bitbucket.org/2.0/repositories/atlassian/atlaskit-mk-2?fields=-project';

    /**
     * @return RepositoryApiState|null null if the repository is not hosted on a supported host
     */
    public function check(string $repositoryUrl, HttpDownloader $httpDownloader): ?RepositoryApiState
    {
        if (Preg::isMatchStrictGroups('{[@/]github\.com[:/]([^/]+/[^/]+?)(?:\.git)?$}i', $repositoryUrl, $match)) {
            return $this->checkUrl(
                'https://api.github.com/repos/'.$match[1].'/git/refs/heads',
                self::GITHUB_REFERENCE_URL,
                $httpDownloader,
                // 409 is an empty repo which is about the same for our purposes, 451 a DMCA takedown
                static fn (TransportException $e) => \in_array($e->getStatusCode(), [404, 409, 451], true)
                    || ($e->getStatusCode() === 403 && str_contains('"message": "Repository access blocked"', (string) $e->getResponse())),
            );
        }

        if (Preg::isMatchStrictGroups('{https://gitlab\.com/([^/]+/.+)$}i', $repositoryUrl, $match)) {
            return $this->checkUrl(
                'https://gitlab.com/api/v4/projects/'.urlencode($match[1]),
                self::GITLAB_REFERENCE_URL,
                $httpDownloader,
                static fn (TransportException $e) => $e->getStatusCode() === 404,
            );
        }

        if (Preg::isMatchStrictGroups('{[@/]bitbucket\.org[:/]([^/]+)/([^/]+?)(?:\.git)?/?$}i', $repositoryUrl, $match)) {
            return $this->checkUrl(
                'https://api.bitbucket.org/2.0/repositories/'.$match[1].'/'.$match[2].'?fields=-project',
                self::BITBUCKET_REFERENCE_URL,
                $httpDownloader,
                static fn (TransportException $e) => $e->getStatusCode() === 404,
            );
        }

        return null;
    }

    /**
     * @param callable(TransportException): bool $isMissing
     */
    private function checkUrl(string $url, string $referenceUrl, HttpDownloader $httpDownloader, callable $isMissing): RepositoryApiState
    {
        try {
            $httpDownloader->get($url, ['retry-auth-failure' => false]);

            return RepositoryApiState::Exists;
        } catch (\Throwable $e) {
            if (!$e instanceof TransportException || !$isMissing($e)) {
                return RepositoryApiState::Unknown;
            }
        }

        try {
            // the reference repo being visible rules out the host or something in between glitching
            $httpDownloader->get($referenceUrl, ['retry-auth-failure' => false]);
        } catch (\Throwable) {
            return RepositoryApiState::Unknown;
        }

        return RepositoryApiState::Missing;
    }
}
