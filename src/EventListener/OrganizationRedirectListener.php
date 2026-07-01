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

use App\Organization\Http\OrganizationRenamedException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

/**
 * Redirects a slug that was freed by a rename to the organization's current slug.
 *
 * The redirect is rebuilt from the current route, swapping only the `organization` parameter,
 * so it works for any organization route (show, settings, ...). A 302 is used on purpose: the
 * underlying reservation is temporary and can be released, so clients must not cache the redirect.
 */
class OrganizationRedirectListener
{
    public function __construct(
        private readonly UrlGeneratorInterface $router
    ) {
    }

    #[AsEventListener]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof OrganizationRenamedException) {
            return;
        }

        $request = $event->getRequest();
        $params = $request->attributes->all('_route_params');
        $params['organization'] = $exception->currentSlug;

        $url = $this->router->generate($request->attributes->getString('_route'), $params);
        if (null !== $queryString = $request->getQueryString()) {
            $url .= '?' . $queryString;
        }

        $event->setResponse(new RedirectResponse($url, Response::HTTP_FOUND));
    }
}
