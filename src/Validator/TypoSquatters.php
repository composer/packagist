<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** @Annotation() */
class TypoSquatters extends Constraint
{
    /** @readonly */
    public string $message = 'Your package name "{{ name }}" is blocked as its name is too close to "{{ existing }}"';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
