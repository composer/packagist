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
 * Validates a string by constructing the given value object from it. The value object constructor
 * canonicalises and validates the input, throwing a
 * {@see \App\Organization\Domain\Exception\DomainValidationException} whose message is shown as the violation.
 */
#[\Attribute(\Attribute::TARGET_PROPERTY)]
class ValidValueObject extends Constraint
{
    /** @var class-string */
    public string $class;

    /**
     * @param class-string $class value object constructed via `new $class($value)`; on invalid
     *                            input it must throw a DomainValidationException
     * @param array<string>|null $groups
     */
    public function __construct(string $class, ?array $groups = null, mixed $payload = null)
    {
        parent::__construct([], $groups, $payload);

        $this->class = $class;
    }

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
