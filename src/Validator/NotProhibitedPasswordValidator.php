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

namespace App\Validator;

use App\Entity\User;
use Symfony\Component\Form\Form;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NotProhibitedPasswordValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotProhibitedPassword) {
            throw new UnexpectedTypeException($constraint, NotProhibitedPassword::class);
        }

        if (!$value instanceof User) {
            throw new UnexpectedTypeException($value, User::class);
        }

        $form = $this->context->getRoot();
        if (!$form instanceof Form) {
            throw new UnexpectedTypeException($form, Form::class);
        }

        $user = $value;
        $password = $form->get('plainPassword')->getData();

        $prohibitedPasswords = [
            $user->getEmail(),
            $user->getEmailCanonical(),
            $user->getUsername(),
            $user->getUsernameCanonical(),
        ];

        foreach ($prohibitedPasswords as $prohibitedPassword) {
            if ($password === $prohibitedPassword) {
                $this->context
                    ->buildViolation($constraint->message)
                    ->atPath('plainPassword')
                    ->addViolation();

                return;
            }
        }
    }
}
