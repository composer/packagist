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

use Symfony\Component\Validator\Constraint;

/**
 * Internal class used in InvisibleRecaptchaType directly, do not use
 */
class RateLimitingRecaptcha extends Constraint
{
    public const INVALID_RECAPTCHA_ERROR = 'invalid-recaptcha';

    public const INVALID_RECAPTCHA_MESSAGE = 'Invalid ReCaptcha.';
    public const MISSING_RECAPTCHA_MESSAGE = 'We detected too many failed attempts. Please try again with ReCaptcha.';

    // !! Must be set on the InvisibleRecaptchaType options
    // If this is set to true, the RecaptchaHelper::increaseCounter must be called on failure (typically wrong password) to trigger the recaptcha enforcement after X attempts
    // by default (false) recaptcha will always be required
    public bool $onlyShowAfterIncrementTrigger = false;
}
