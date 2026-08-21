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

namespace App\Organization\Domain;

/**
 * An invited email address. Guards format only and keeps the casing it was given, for display. Matching
 * it against a user's account email and detecting duplicate pending invitations are case-insensitive,
 * so no lower-cased form is kept alongside it.
 */
final readonly class Email
{
    public const int MAX_LENGTH = 255;

    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Please provide a valid email address.');
        }

        $this->value = $value;
    }

    public function isIdentical(self|string $other): bool
    {
        return mb_strtolower($this->value) === mb_strtolower($other instanceof self ? $other->value : $other);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
