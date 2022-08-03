<?php

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

/** @Annotation() */
#[Attribute(Attribute::TARGET_CLASS)]
class Copyright extends Constraint
{
    /** @readonly */
    public string $message = '';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
