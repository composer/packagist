<?php declare(strict_types=1);

namespace App\EventListener;

use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaException;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaVerifier;
use FOS\UserBundle\Event\GetResponseNullableUserEvent;
use FOS\UserBundle\FOSUserEvents;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;

class RecaptchaListener implements EventSubscriberInterface
{
    private RecaptchaVerifier $recaptchaVerifier;
    private UrlGeneratorInterface $urlGenerator;

    public function __construct(RecaptchaVerifier $recaptchaVerifier, UrlGeneratorInterface $urlGenerator)
    {
        $this->recaptchaVerifier = $recaptchaVerifier;
        $this->urlGenerator = $urlGenerator;
    }

    public function onInitializeReset(GetResponseNullableUserEvent $event): void
    {
        try {
            $this->recaptchaVerifier->verify();
        } catch (RecaptchaException $e) {
            $event->getRequest()->getSession()->getFlashBag()->add('error', 'Invalid ReCaptcha');
            $event->setResponse(new RedirectResponse($this->urlGenerator->generate('fos_user_resetting_request')));
        }
    }

    public static function getSubscribedEvents(): iterable
    {
        return [
            FOSUserEvents::RESETTING_SEND_EMAIL_INITIALIZE => 'onInitializeReset',
        ];
    }
}
