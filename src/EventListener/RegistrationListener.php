<?php

namespace App\EventListener;

use FOS\UserBundle\Event\FormEvent;
use FOS\UserBundle\FOSUserEvents;
use FOS\UserBundle\Util\TokenGeneratorInterface;
use App\Entity\User;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * @author Sullivan Senechal <soullivaneuh@gmail.com>
 */
class RegistrationListener implements EventSubscriberInterface
{
    private TokenGeneratorInterface $tokenGenerator;

    /**
     * @param TokenGeneratorInterface $tokenGenerator
     */
    public function __construct(TokenGeneratorInterface $tokenGenerator)
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
