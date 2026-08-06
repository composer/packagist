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
 * An invited email address. Guards format only and exposes the canonical (lower-cased) form used to
 * match against a user's account email and to detect duplicate pending invitations. The original casing
 * is preserved in {@see self::$value} for display.
 */
final readonly class Email
{
    public const int MAX_LENGTH = 255;

    public string $value;

    public string $canonical;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH || filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
            throw new \InvalidArgumentException('Please provide a valid email address.');
        }

        $this->value = $value;
        $this->canonical = mb_strtolower($value);
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
