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

use App\Organization\Domain\DisplayName;
use App\Organization\Domain\Exception\InvalidDisplayNameException;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class ValidDisplayNameValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof ValidDisplayName) {
            throw new UnexpectedTypeException($constraint, ValidDisplayName::class);
        }

        // Empty values are handled by NotBlank constraint.
        if (!\is_string($value) || $value === '') {
            return;
        }

        try {
            new DisplayName($value);
        } catch (InvalidDisplayNameException $e) {
            $this->context->buildViolation($e->getMessage())->addViolation();
        }
    }
}
