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

use App\Organization\Domain\Exception\InvalidTeamNameException;
use Composer\Pcre\Preg;

/**
 * Team name: letters, numbers, spaces and hyphens, max 40 characters.
 *
 * Format only. The reserved-name check (`owners`) and case-insensitive uniqueness within the
 * org are enforced by the {@see Organization} aggregate, mirroring how slug reserved-words and
 * uniqueness live outside {@see Slug}.
 */
final readonly class TeamName
{
    public const int MAX_LENGTH = 40;

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
            throw new InvalidTeamNameException(sprintf('The team name must be between 1 and %d characters.', self::MAX_LENGTH));
        }

        if (!Preg::isMatch('/^' . self::PATTERN . '$/u', $value)) {
            throw new InvalidTeamNameException('The team name may only contain letters, numbers, spaces and hyphens.');
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
