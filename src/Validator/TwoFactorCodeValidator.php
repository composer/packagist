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

use Scheb\TwoFactorBundle\Security\TwoFactor\Provider\Totp\TotpAuthenticatorInterface;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class TwoFactorCodeValidator extends ConstraintValidator
{
    public function __construct(
        private readonly TotpAuthenticatorInterface $totpAuthenticator,
    ) {}

    /**
     * @param TwoFactorCode $constraint
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$this->totpAuthenticator->checkCode($constraint->user, (string) $value)) {
            $this->context->addViolation($constraint->message);
        }
    }
}
