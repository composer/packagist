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

use Psr\Log\LoggerInterface;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpKernel\Event\RequestEvent;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * Checks that POST, PUT, PATCH and DELETE requests always have an Origin set
 *
 * Defense in depth for CSRF
 */
class OriginListener
{
    public function __construct(
        private string $packagistHost,
        private string $environment,
        private LoggerInterface $logger,
    ) {
    }

    // must happen very early to make sure the request never reaches the firewall/authentication if it isn't valid at all
    #[AsEventListener(priority: 90)]
    public function onRequest(RequestEvent $event): void
    {
        if ('test' === $this->environment) {
            // We are not checking Origin-headers in test-environments
            return;
        }

        if ($event->getRequest()->isMethodSafe()) {
            // We are not checking safe requests
            return;
        }

        if (!$event->getRequest()->cookies->has('packagist') && !$event->getRequest()->cookies->has('pauth')) {
            // API-requests or any request made without a session or remember-me header is fine without Origin header
            return;
        }

        // valid origin
        $origin = $event->getRequest()->headers->get('Origin') ?? '';
        if ($origin === 'https://'.$this->packagistHost) {
            return;
        }

        // valid as well with HTTP in dev
        $scheme = parse_url($origin, PHP_URL_SCHEME);
        $host = parse_url($origin, PHP_URL_HOST);
        $knownOrigin = $scheme.'://'.$host;
        if ('dev' === $this->environment && $knownOrigin === 'http://'.$this->packagistHost) {
            return;
        }

        $this->logger->warning('Request did not contain a valid Origin Header', ['request' => $event->getRequest()]);

        throw new BadRequestHttpException('Request did not contain a valid Origin Header');
    }
}
