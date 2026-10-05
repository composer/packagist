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

namespace App\Util;

use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Util\Http\Response;
use Composer\Util\HttpDownloader;
use Graze\DogStatsD\Client as StatsDClient;
use React\Promise\PromiseInterface;

class LoggingHttpDownloader extends HttpDownloader
{
    /** For use in fixtures loading only */
    private bool $loadMinimalVersions = false;

    /**
     * @param (\Closure(): ?string)|null $packagistTokenProvider used to retry repo data requests a maintainer's token is refused for
     */
    public function __construct(
        private IOInterface $io,
        Config $config,
        private StatsDClient $statsd,
        private bool $usesPackagistToken,
        private string $vendor,
        private ?\Closure $packagistTokenProvider = null,
    ) {
        parent::__construct($io, $config, HttpDownloaderOptionsFactory::getOptions());
    }

    public function get($url, $options = []): Response
    {
        $this->track($url);

        try {
            $result = $this->doGet($url, $options);
        } catch (TransportException $e) {
            $this->reportGitHubApiFailure($url, $e);
            if (!$this->switchToPackagistToken($url, $e)) {
                throw $e;
            }

            return $this->get($url, $options);
        }

        if ($this->loadMinimalVersions && Preg::isMatch('{/(tags|git/refs/heads)(\?|$)}', $url)) {
            $reflProp = new \ReflectionProperty(Response::class, 'request');
            $newBody = $result->decodeJson();
            $newBody = \array_slice($newBody, 0, 1);
            $result = new Response($reflProp->getValue($result), $result->getStatusCode(), [], (string) json_encode($newBody));
        }

        return $result;
    }

    public function add($url, $options = []): PromiseInterface
    {
        $this->track($url);

        return parent::add($url, $options);
    }

    public function copy($url, $to, $options = []): Response
    {
        $this->track($url);

        return parent::copy($url, $to, $options);
    }

    public function addCopy($url, $to, $options = []): PromiseInterface
    {
        $this->track($url);

        return parent::addCopy($url, $to, $options);
    }

    /**
     * @param mixed[] $options
     */
    protected function doGet(string $url, array $options): Response
    {
        return parent::get($url, $options);
    }

    /**
     * @internal for use in fixtures only
     */
    public function loadMinimalVersions(): void
    {
        $this->loadMinimalVersions = true;
    }

    private function track(string $url): void
    {
        if (!str_starts_with($url, 'https://api.github.com/')) {
            return;
        }

        $tags = [
            'uses_packagist_token' => $this->usesPackagistToken ? '1' : '0',
        ];

        if ($this->usesPackagistToken) {
            $tags['vendor'] = $this->vendor;
        }

        $this->statsd->increment('github_api_request', tags: $tags);
    }

    /**
     * The job output is public, so this only reports the status and a coarse reason, never the
     * response body (GitHub SSO errors embed an authorization URL for the token).
     */
    private function reportGitHubApiFailure(string $url, TransportException $e): void
    {
        if (!str_starts_with($url, 'https://api.github.com/')) {
            return;
        }

        $status = $e->getStatusCode() ?? $e->getCode();
        // other 404s are expected, e.g. a branch without composer.json
        $isRepoDataUrl = Preg::isMatch('{^https://api\.github\.com/repos/[^/]+/[^/?]+$}', $url);
        if (!$isRepoDataUrl && !\in_array($status, [401, 403, 429], true)) {
            return;
        }

        $headers = $e->getHeaders() ?? [];
        $reason = match (true) {
            401 === $status => 'token rejected',
            null !== Response::findHeaderValue($headers, 'x-github-sso') => 'token not authorized for the organization\'s SAML SSO',
            '0' === Response::findHeaderValue($headers, 'x-ratelimit-remaining') => 'rate limit exhausted',
            str_contains((string) $e->getResponse(), 'IP allow list') => 'organization IP allow list blocks the token',
            default => null,
        };

        $this->io->writeError(\sprintf(
            '<warning>GitHub API request to %s failed with HTTP %s%s using %s</warning>',
            Preg::replace('{\?.*$}', '', $url),
            $status,
            null !== $reason ? ' ('.$reason.')' : '',
            $this->usesPackagistToken ? 'the Packagist token' : 'a maintainer\'s token',
        ));

        $this->statsd->increment('github_api_failure', tags: [
            'status' => (string) $status,
            'reason' => $reason ?? 'unknown',
            'uses_packagist_token' => $this->usesPackagistToken ? '1' : '0',
        ]);
    }

    /**
     * A failed repo data request makes Composer's GitHubDriver fall back to a git clone, which skips
     * the README and GitHub metadata, so retry it with our token when the maintainer's is refused
     * (org IP allow list, SAML SSO, revoked, rate limited).
     */
    private function switchToPackagistToken(string $url, TransportException $e): bool
    {
        if ($this->usesPackagistToken || null === $this->packagistTokenProvider) {
            return false;
        }
        if (!\in_array($e->getStatusCode() ?? $e->getCode(), [401, 403, 404], true)) {
            return false;
        }
        if (!Preg::isMatch('{^https://api\.github\.com/repos/[^/]+/[^/?]+$}', $url)) {
            return false;
        }

        $token = ($this->packagistTokenProvider)();
        if (null === $token) {
            return false;
        }

        $this->io->writeError('<warning>Retrying with the Packagist token</warning>');
        $this->io->setAuthentication('github.com', $token, 'x-oauth-basic');
        $this->usesPackagistToken = true;

        return true;
    }
}
