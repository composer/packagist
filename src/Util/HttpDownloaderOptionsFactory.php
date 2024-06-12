<?php

namespace App\Util;

use Composer\Util\Platform;
use Symfony\Component\HttpFoundation\IpUtils;

class HttpDownloaderOptionsFactory
{
    /**
     * @return array{http: array{header: list<string>}, prevent_ip_access_callable: (callable(string): bool), max_file_size: int}
     */
    public static function getOptions(): array
    {
        $options['http']['header'][] = 'User-Agent: Packagist.org';
        $options['prevent_ip_access_callable'] = fn (string $ip) => IpUtils::isPrivateIp($ip);
        $options['max_file_size'] = 128_000_000;

        Platform::putEnv('COMPOSER_MAX_PARALLEL_HTTP', '99');

        return $options;
    }
}
