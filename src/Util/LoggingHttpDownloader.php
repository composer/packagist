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

    public function __construct(
        IOInterface $io,
        Config $config,
        private StatsDClient $statsd,
        private bool $usesPackagistToken,
        private string $vendor,
    ) {
        parent::__construct($io, $config, HttpDownloaderOptionsFactory::getOptions());
    }

    public function get($url, $options = []): Response
    {
        $this->track($url);

        $result = parent::get($url, $options);

        if ($this->loadMinimalVersions && Preg::isMatch('{/(tags|git/refs/heads)(\?|$)}', $url)) {
            $reflProp = new \ReflectionProperty(Response::class, 'request');
            $newBody = $result->decodeJson();
            $newBody = array_slice($newBody, 0, 1);
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
}
