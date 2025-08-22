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
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NotReservedWordValidator extends ConstraintValidator
{
    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotReservedWord) {
            throw new UnexpectedTypeException($constraint, NotReservedWord::class);
        }

        if (!is_string($value)) {
            return;
        }

        $reservedWords = [
            'composer',
            'packagist',
            'php',
            'automation', // used to describe background workers doing things automatically in audit log
        ];

        foreach ($reservedWords as $reservedWord) {
            if ($reservedWord === mb_strtolower($value)) {
                $this->context
                    ->buildViolation($constraint->message)
                    ->addViolation();

                return;
            }
        }
    }
}
