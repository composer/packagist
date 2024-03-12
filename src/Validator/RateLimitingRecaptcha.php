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

class RateLimitingRecaptcha extends Constraint
{
    public const INVALID_RECAPTCHA_ERROR = 'invalid-recaptcha';

    public const INVALID_RECAPTCHA_MESSAGE = 'Invalid ReCaptcha.';
    public const MISSING_RECAPTCHA_MESSAGE = 'We detected too many failed attempts. Please try again with ReCaptcha.';
}
