<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraint;

/** @Annotation() */
class Copyright extends Constraint
{
    /** @readonly */
    public string $message = '';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
