<?php

namespace App\EventListener;

use FOS\UserBundle\Event\GetResponseUserEvent;
use FOS\UserBundle\FOSUserEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Security\Core\Authorization\AuthorizationChecker;
use Symfony\Component\Security\Core\Authorization\Voter\AuthenticatedVoter;

/**
 * Redirect logged in user to the homepage when accessing registration page
 */
class LoggedInUserCannotRegisterListener implements EventSubscriberInterface
{
    /** @var \Symfony\Component\Security\Core\Authorization\AuthorizationChecker */
    private $authorizationChecker;

    /** @var \Symfony\Component\Routing\Generator\UrlGeneratorInterface */
    private $router;

    public function __construct(
        AuthorizationChecker $authorizationChecker,
        UrlGeneratorInterface $router
    )
    {
        $this->authorizationChecker = $authorizationChecker;
        $this->router = $router;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            FOSUserEvents::REGISTRATION_INITIALIZE => 'onRegistrationInitialize',
        ];
    }

    /**
     * @param \FOS\UserBundle\Event\GetResponseUserEvent $event
     */
    public function onRegistrationInitialize(GetResponseUserEvent $event)
    {
        // don't do anything if the user is not logged in
        if (!$this->authorizationChecker->isGranted(AuthenticatedVoter::IS_AUTHENTICATED_REMEMBERED)) {
            return;
        }

        $homepageUrl = $this->router->generate('home');
        $redirectResponse = new RedirectResponse($homepageUrl);
        $event->setResponse($redirectResponse);
    }
}
