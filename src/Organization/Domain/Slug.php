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

use App\Organization\Domain\Exception\InvalidSlugException;
use Composer\Pcre\Preg;

/**
 * Organization slug: lower-case alphanumerics and hyphens, no leading/trailing hyphen,
 * max 20 characters. This value object guards format only; uniqueness, the reserved-word
 * deny-list and vendor-prefix collisions are external facts checked elsewhere.
 */
final readonly class Slug
{
    public const int MAX_LENGTH = 20;

    public const string PATTERN = '[a-z0-9]+(?:-[a-z0-9]+)*';

    public string $value;

    /**
     * Canonicalise raw user input (trim, lower-case)
     */
    public static function fromUserInput(string $value): self
    {
        return new self(mb_strtolower(trim($value)));
    }

    public function __construct(string $value)
    {
        if ($value === '' || mb_strlen($value) > self::MAX_LENGTH) {
            throw new InvalidSlugException(sprintf('The slug must be between 1 and %d characters.', self::MAX_LENGTH));
        }

        if (!Preg::isMatch('/^' . self::PATTERN . '$/', $value)) {
            throw new InvalidSlugException('The slug may only contain lowercase letters, numbers and hyphens, with no leading or trailing hyphen.');
        }

        $this->value = $value;
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
