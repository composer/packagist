<?php

namespace App\EventListener;

use FOS\UserBundle\Event\FormEvent;
use FOS\UserBundle\FOSUserEvents;
use FOS\UserBundle\Util\TokenGenerator;
use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @author Sullivan Senechal <soullivaneuh@gmail.com>
 */
class RegistrationListener implements EventSubscriberInterface
{
    /**
     * @var TokenGenerator
     */
    private $tokenGenerator;

    /**
     * @param TokenGenerator $tokenGenerator
     */
    public function __construct(TokenGenerator $tokenGenerator)
    {
        $this->tokenGenerator = $tokenGenerator;
    }

    /**
     * {@inheritdoc}
     */
    public static function getSubscribedEvents()
    {
        return [
            FOSUserEvents::REGISTRATION_SUCCESS => 'onRegistrationSuccess'
        ];
    }

    public function onRegistrationSuccess(FormEvent $event)
    {
        /** @var User $user */
        $user = $event->getForm()->getData();
        $apiToken = substr($this->tokenGenerator->generateToken(), 0, 20);
        $user->setApiToken($apiToken);
    }
}
