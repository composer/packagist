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

use App\Log\ActorLabel;
use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

class NotReservedWordValidator extends ConstraintValidator
{
    /**
     * Names reserved across Packagist (usernames, organization slugs).
     *
     * @var list<string>
     */
    private const array RESERVED_WORDS = [
        'composer',
        'packagist',
        'php',
        'admin', // actor label in older log entries, now 'packagist-admin'
        'create', // used in the URL when creating an organization
    ];

    /** @return list<string> */
    public static function reservedWords(): array
    {
        // Log actor labels identify background workers, unknown actors and admins
        return [...self::RESERVED_WORDS, ...array_column(ActorLabel::cases(), 'value')];
    }

    public function validate(mixed $value, Constraint $constraint): void
    {
        if (!$constraint instanceof NotReservedWord) {
            throw new UnexpectedTypeException($constraint, NotReservedWord::class);
        }

        if (!\is_string($value)) {
            return;
        }

        if (\in_array(mb_strtolower($value), self::reservedWords(), true)) {
            $this->context
                ->buildViolation($constraint->message)
                ->setParameter('{{ value }}', $value)
                ->addViolation();
        }
    }
}
