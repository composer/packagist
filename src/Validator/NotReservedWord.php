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
    /**
     * Names reserved across Packagist (usernames, organization slugs).
     *
     * @var list<string>
     */
    public const array RESERVED_WORDS = [
        'composer',
        'packagist',
        'php',
        'automation', // used to describe background workers doing things automatically in transparency log
    ];

    public string $message = 'This is a reserved word.';

    public function getTargets(): string
    {
        return self::PROPERTY_CONSTRAINT;
    }
}
