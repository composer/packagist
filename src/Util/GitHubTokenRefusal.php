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

use Composer\Downloader\TransportException;
use Composer\Util\Http\Response;

/**
 * Why GitHub refused a token, the values are shown in job output and statsd tags
 */
enum GitHubTokenRefusal: string
{
    case Rejected = 'token rejected';
    case SamlSso = 'token not authorized for the organization\'s SAML SSO';
    case RateLimited = 'rate limit exhausted';
    case IpAllowList = 'organization IP allow list blocks the token';

    public static function fromFailure(TransportException $e): ?self
    {
        $status = $e->getStatusCode() ?? $e->getCode();
        $headers = $e->getHeaders() ?? [];

        return match (true) {
            401 === $status => self::Rejected,
            null !== Response::findHeaderValue($headers, 'x-github-sso') => self::SamlSso,
            '0' === Response::findHeaderValue($headers, 'x-ratelimit-remaining') => self::RateLimited,
            str_contains((string) $e->getResponse(), 'IP allow list') => self::IpAllowList,
            default => null,
        };
    }
}
