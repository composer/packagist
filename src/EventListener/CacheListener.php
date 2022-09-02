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

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\ResponseEvent;

class CacheListener
{
    public function onResponse(ResponseEvent $e): void
    {
        $resp = $e->getResponse();

        // add nginx-cache compatible header
        if ($resp->headers->hasCacheControlDirective('public') && ($cache = $resp->headers->getCacheControlDirective('s-maxage'))) {
            $resp->headers->set('X-Accel-Expires', (string) $cache);
        }
    }
}
