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

use App\Logger\LogIdProcessor;
use Graze\DogStatsD\Client;
use Monolog\Logger;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Event\ResponseEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class RequestStatsListener
{
    private ?float $pageTiming = null;

    public function __construct(
        private Client $statsd,
        private Logger $logger,
        private LogIdProcessor $logIdProcessor,
    ) {
    }

    #[AsEventListener(priority: 1000)]
    public function onRequest(RequestEvent $e): void
    {
        if (!$e->isMainRequest()) {
            return;
        }
        $this->pageTiming = microtime(true);

        $this->logIdProcessor->startRequest();
        if ($e->getRequest()->getContent() !== null) {
            $this->logger->debug('Request content received', ['content' => substr($e->getRequest()->getContent(), 0, 10_000)]);
        }
    }

    #[AsEventListener(priority: -1000)]
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

    #[AsEventListener()]
    public function onException(ExceptionEvent $e): void
    {
        if ($e->getThrowable() instanceof BadRequestHttpException && $e->getThrowable()->getCode() !== 404) {
            $this->logger->error('Bad request', ['exception' => $e->getThrowable()]);
        }
    }
}
