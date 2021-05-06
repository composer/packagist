<?php

namespace App\Validator;

use Symfony\Component\Validator\Constraints\Compound;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * @Annotation
 */
class Password extends Compound
{
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank([
                'message' => 'Please enter a password',
            ]),
            new Assert\Type('string'),
            new Assert\Length([
                'min' => 8,
                'minMessage' => 'Your password should be at least {{ limit }} characters',
                // max length allowed by Symfony for security reasons
                'max' => 4096,
            ]),
            new Assert\NotCompromisedPassword(),
        ];
    }
}
