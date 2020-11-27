<?php

namespace App\EventListener;

use Symfony\Component\HttpKernel\Event\FilterResponseEvent;

class CacheListener
{
    public function onResponse(FilterResponseEvent $e)
    {
        $resp = $e->getResponse();

        // add nginx-cache compatible header
        if ($resp->headers->hasCacheControlDirective('public') && ($cache = $resp->headers->getCacheControlDirective('s-maxage'))) {
            $resp->headers->set('X-Accel-Expires', $cache);
        }
    }
}
