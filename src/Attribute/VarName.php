<?php

namespace App\Attribute;

#[\Attribute(\Attribute::TARGET_PARAMETER)]
class VarName
{
    public function __construct(
        /**
         * @readonly
         */
        public string $name,
    ) {}
}
