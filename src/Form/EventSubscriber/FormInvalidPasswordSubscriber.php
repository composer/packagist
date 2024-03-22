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

use App\Security\RecaptchaHelper;
use App\Validator\TwoFactorCode;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\ConstraintViolation;

class FormInvalidPasswordSubscriber implements EventSubscriberInterface
{
    public function __construct(
        private readonly RecaptchaHelper $recaptchaHelper,
    ) {}

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();

        if ($form->isRoot()) {
            foreach ($form->getErrors(true) as $error) {
                $cause = $error->getCause();
                // increment for invalid password
                if ($cause instanceof ConstraintViolation && $cause->getCode() === UserPassword::INVALID_PASSWORD_ERROR) {
                    $context = $this->recaptchaHelper->buildContext();
                    $this->recaptchaHelper->increaseCounter($context);
                }

                // increment for invalid 2fa code
                if ($cause instanceof ConstraintViolation && $cause->getConstraint() instanceof TwoFactorCode) {
                    $context = $this->recaptchaHelper->buildContext();
                    $this->recaptchaHelper->increaseCounter($context);
                }
            }
        }
    }

    public static function getSubscribedEvents(): array
    {
        return [FormEvents::POST_SUBMIT => ['onPostSubmit', -1024]];
    }
}
