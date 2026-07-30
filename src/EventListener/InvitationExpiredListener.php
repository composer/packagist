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

use App\Organization\Http\InvitationExpiredException;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Twig\Environment;

/**
 * Renders the invitee-facing expiry page for an invitation link that is genuine but no longer usable,
 * as flagged by {@see \App\ArgumentResolver\OrganizationInvitationResolver}. The 410 keeps the link out
 * of caches and crawlers while the page explains how to get a fresh invitation.
 */
class InvitationExpiredListener
{
    public function __construct(
        private readonly Environment $twig,
    ) {
    }

    #[AsEventListener]
    public function onKernelException(ExceptionEvent $event): void
    {
        $exception = $event->getThrowable();
        if (!$exception instanceof InvitationExpiredException) {
            return;
        }

        $event->setResponse(new Response(
            $this->twig->render('organization/invitation_expired.html.twig', ['expiresAt' => $exception->expiresAt]),
            Response::HTTP_GONE,
        ));
    }
}
