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

use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Slug;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidSlugValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidSlug) {
            throw new UnexpectedTypeException($constraint, ValidSlug::class);
        }

        // Empty values are handled by NotBlank constraint.
        if (!\is_string($value) || $value === '') {
            return;
        }

        try {
            Slug::fromUserInput($value);
        } catch (InvalidSlugException $e) {
            $this->context->buildViolation($e->getMessage())->addViolation();
        }
    }
}
