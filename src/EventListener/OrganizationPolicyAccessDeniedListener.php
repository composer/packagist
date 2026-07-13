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

use App\Entity\User;
use App\Security\Voter\OrganizationAccessDeniedReason;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\Session\FlashBagAwareSessionInterface;
use Symfony\Component\HttpKernel\Event\ExceptionEvent;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * Turns an organization access denial into a helpful redirect where one exists, e.g. an owner blocked
 * only by missing 2FA (flagged by {@see \App\Security\Voter\OrganizationVoter}) is sent to enable it
 * rather than shown a bare 403.
 */
class OrganizationPolicyAccessDeniedListener
{
    public function __construct(
        private readonly Security $security,
        private readonly UrlGeneratorInterface $router,
    ) {
    }

    // Runs ahead of the security firewall's exception listener (priority 1), which otherwise rewraps
    // the AccessDeniedException into an AccessDeniedHttpException before we could read its decision.
    #[AsEventListener(priority: 64)]
    public function onKernelException(ExceptionEvent $event): void
    {
        $throwable = $event->getThrowable();
        if (!$throwable instanceof AccessDeniedException || !($reason = $this->getOrganizationAccessDeniedReason($throwable))) {
            return;
        }

        $user = $this->security->getUser();
        if (!$user instanceof User) {
            return;
        }

        $session = $event->getRequest()->getSession();
        if ($session instanceof FlashBagAwareSessionInterface) {
            $session->getFlashBag()->add('error', $reason->message());
        }

        if ($reason === OrganizationAccessDeniedReason::TwoFactorRequired) {
            $event->setResponse(new RedirectResponse(
                $this->router->generate('user_2fa_configure', ['name' => $user->getUsername()]),
                Response::HTTP_FOUND,
            ));

            $event->stopPropagation();
        }
    }

    private function getOrganizationAccessDeniedReason(AccessDeniedException $exception): ?OrganizationAccessDeniedReason
    {
        $decision = $exception->getAccessDecision();
        if ($decision === null) {
            return null;
        }

        foreach ($decision->votes as $vote) {
            if (isset($vote->extraData[OrganizationAccessDeniedReason::VOTE_KEY]) && $vote->extraData[OrganizationAccessDeniedReason::VOTE_KEY] instanceof OrganizationAccessDeniedReason) {
                return $vote->extraData[OrganizationAccessDeniedReason::VOTE_KEY];
            }
        }

        return null;
    }
}
