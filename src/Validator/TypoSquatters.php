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
use Symfony\Component\Validator\Constraint;

#[Attribute(Attribute::TARGET_CLASS)]
class TypoSquatters extends Constraint
{
    /** @readonly */
    public string $message = 'Your package name "{{ name }}" is blocked as its name is too close to "{{ existing }}"';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
