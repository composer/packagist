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

namespace App\Form;

use App\Validator\TotpCode;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Form\FormError;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

class TotpCodeFormValidator
{
    public function __construct(private ValidatorInterface $validator)
    {
    }

    public function onPostSubmit(FormEvent $event): void
    {
        $form = $event->getForm();

        if ($form->isValid()) {
            $this->validateCode($form->get('code'), $form->getConfig()->getOption('user'));
        }
    }

    /** @param FormInterface<mixed> $field */
    public function validateCode(FormInterface $field, TwoFactorInterface $user): bool
    {
        foreach ($this->validator->validate($field->getData(), new TotpCode($user)) as $violation) {
            $field->addError(new FormError((string) $violation->getMessage(), cause: $violation));

            return false;
        }

        return true;
    }
}
