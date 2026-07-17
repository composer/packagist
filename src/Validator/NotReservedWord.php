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

#[\Attribute(\Attribute::TARGET_PROPERTY)]
class NotReservedWord extends Constraint
{
    public string $message = '"{{ value }}" is a reserved name and cannot be used.';

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
