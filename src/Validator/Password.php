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

use Attribute;
use Symfony\Component\Validator\Constraints\Compound;
use Symfony\Component\Validator\Constraints as Assert;

#[Attribute(Attribute::TARGET_PROPERTY)]
class Password extends Compound
{
    /**
     * @param array<string, mixed> $options
     */
    protected function getConstraints(array $options): array
    {
        return [
            new Assert\NotBlank(message: 'Please enter a password'),
            new Assert\Type('string'),
            new Assert\Length(
                min: 8,
                minMessage: 'Your password should be at least {{ limit }} characters',
                // max length allowed by Symfony for security reasons
                max: 4096,
            ),
            new Assert\NotCompromisedPassword(skipOnError: true),
        ];
    }
}
