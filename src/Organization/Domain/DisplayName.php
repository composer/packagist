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

use App\Organization\Domain\Exception\InvalidDisplayNameException;
use Composer\Pcre\Preg;

/**
 * Organization display name: letters, numbers, spaces and hyphens, max 60 characters.
 */
final readonly class DisplayName
{
    public const int MAX_LENGTH = 60;

    /** Letters, numbers, spaces and hyphens. */
    public const string PATTERN = '[\p{L}\p{N}\- ]+';

    public string $value;

    /**
     * Canonicalise raw user input (trim surrounding whitespace).
     */
    public static function fromUserInput(string $value): self
    {
        return new self(trim($value));
    }

    public function __construct(string $value)
    {
        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidDisplayNameException(sprintf('The display name must be between 1 and %d characters.', self::MAX_LENGTH));
        }

        if (!Preg::isMatch('/^' . self::PATTERN . '$/u', $value)) {
            throw new InvalidDisplayNameException('The display name may only contain letters, numbers, spaces and hyphens.');
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
