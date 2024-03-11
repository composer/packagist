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

use App\Security\RecaptchaHelper;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaException;
use Beelab\Recaptcha2Bundle\Recaptcha\RecaptchaVerifier;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;

class RateLimitingRecaptchaValidator extends ConstraintValidator
{
    public function __construct(
        private readonly RecaptchaHelper $recaptchaHelper,
        private readonly RecaptchaVerifier $recaptchaVerifier,
    ) {}

    /**
     * @param RateLimitingRecaptcha $constraint
     */
    public function validate(mixed $value, Constraint $constraint): void
    {
        $context = $this->recaptchaHelper->buildContext();

        if (! $this->recaptchaHelper->requiresRecaptcha($context)) {
            return;
        }

        try {
            $this->recaptchaVerifier->verify();
        } catch (RecaptchaException) {
            $this->context
                ->buildViolation($context->hasRecaptcha ? RateLimitingRecaptcha::INVALID_RECAPTCHA_MESSAGE : RateLimitingRecaptcha::MISSING_RECAPTCHA_MESSAGE)
                ->setCode(RateLimitingRecaptcha::INVALID_RECAPTCHA_ERROR)
                ->addViolation();
        }
    }
}
