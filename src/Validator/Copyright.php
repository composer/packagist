<?php

namespace App\Validator;

use Attribute;
use Symfony\Component\Validator\Constraint;

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
