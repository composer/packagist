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

namespace App\Form\EventSubscriber;

use App\Validator\RateLimitingRecaptcha;
use Beelab\Recaptcha2Bundle\Validator\Constraints\Recaptcha2;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\Form;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Validator\ConstraintViolation;

/**
 * In case we encounter a brute force error e.g. missing/invalid recaptcha, remove all other form errors to not accidentally leak any information.
 */
class FormBruteForceSubscriber implements EventSubscriberInterface
{
    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();

        if ($form->isRoot() && $form instanceof Form) {
            foreach ($form->getErrors(true) as $error) {
                $recaptchaMessage = new Recaptcha2()->message;
                $cause = $error->getCause();
                if (
                    $cause instanceof ConstraintViolation && (
                        $cause->getCode() === RateLimitingRecaptcha::INVALID_RECAPTCHA_ERROR
                        || $error->getMessage() === $recaptchaMessage
                    )) {
                    $form->clearErrors(true);
                    $error->getOrigin()?->addError($error);
                }
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [FormEvents::POST_SUBMIT => ['onPostSubmit', -2048]];
    }
}
