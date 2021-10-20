<?php declare(strict_types=1);

namespace App\Util;

use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Util\HttpDownloader;
use Graze\DogStatsD\Client as StatsDClient;

class LoggingHttpDownloader extends HttpDownloader
{
    public function __construct(
        IOInterface $io,
        Config $config,
        private StatsDClient $statsd,
        private bool $usesPackagistToken,
        private string $vendor,
    ) {
        parent::__construct($io, $config);
    }

    public function get($url, $options = array())
    {
        $this->track($url);

        return parent::get($url, $options);
    }

    public function add($url, $options = array())
    {
        $this->track($url);

        return parent::add($url, $options);
    }

    public function copy($url, $to, $options = array())
    {
        $this->track($url);

        return parent::copy($url, $to, $options);
    }

    public function addCopy($url, $to, $options = array())
    {
        $this->track($url);

        return parent::addCopy($url, $to, $options);
    }

    private function track(string $url): void
    {
        if (!str_starts_with($url, 'https://api.github.com/')) {
            return;
        }

        $this->statsd->increment('github_api_request', tags: [
            'vendor' => $this->vendor,
            'uses_packagist_token' => $this->usesPackagistToken ? '1' : '0',
        ]);
    }
}
