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

#[\Attribute(\Attribute::TARGET_CLASS)]
class PopularPackageSafety extends Constraint
{
    public string $message = 'This package is very popular and URL editing has been disabled for security reasons. Please add a note on the old repo pointing to the new one if possible then get in touch at contact@packagist.org so we can get it sorted.';

    public function getTargets(): string
    {
        return self::CLASS_CONSTRAINT;
    }
}
