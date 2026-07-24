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

use Composer\Pcre\Preg;

/**
 * Team name: ASCII letters, numbers, spaces and hyphens, max 40 characters.
 *
 * Format only. The reserved-name check (`owners`) and case-insensitive uniqueness within the
 * org are enforced by the {@see Organization} aggregate, mirroring how slug reserved-words and
 * uniqueness live outside {@see Slug}.
 */
final readonly class TeamName
{
    public const int MAX_LENGTH = 40;

    /** ASCII letters, numbers, spaces and hyphens. */
    public const string PATTERN = '[A-Za-z0-9\- ]+';

    public string $value;

    public function __construct(string $value)
    {
        $value = trim($value);

        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            throw new \InvalidArgumentException(sprintf('The team name must be between 1 and %d characters.', self::MAX_LENGTH));
        }

        if (!Preg::isMatch('/^' . self::PATTERN . '$/u', $value)) {
            throw new \InvalidArgumentException('The team name may only contain letters, numbers, spaces and hyphens.');
        }

        $reserved = [mb_strtolower(Organization::OWNERS_TEAM_NAME), mb_strtolower(Organization::ALL_ORGANIZATION_MEMBERS_TEAM_NAME)];
        if (\in_array(mb_strtolower($value), $reserved, true)) {
            throw new \InvalidArgumentException(sprintf('"%s" is a reserved team name.', $value));
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
