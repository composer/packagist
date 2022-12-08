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

use Graze\DogStatsD\Client;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;

class RequestStatsListener
{
    private float|null $pageTiming = null;

    public function __construct(
        private Client $statsd,
    ) {
    }

    #[AsEventListener]
    public function onRequest(RequestEvent $e): void
    {
        if (!$e->isMainRequest()) {
            return;
        }
        $this->pageTiming = microtime(true);
    }

    #[AsEventListener]
    public function onResponse(ResponseEvent $e): void
    {
        if (!$e->isMainRequest()) {
            return;
        }

        if ($this->pageTiming === null) {
            return;
        }

        $this->statsd->timing('app.response_time', (int) ((microtime(true) - $this->pageTiming) * 1000));
        $this->pageTiming = null;

        $statusCode = $e->getResponse()->getStatusCode();
        $statsdCode = match (true) {
            $statusCode < 300 => '2xx',
            $statusCode < 400 => '3xx',
            $statusCode === 404 => '404',
            $statusCode < 500 => '4xx',
            default => '5xx',
        };
        $this->statsd->increment('app.status.'.$statsdCode, 1, 1, ['route' => $e->getRequest()->attributes->get('_route')]);
    }
}
