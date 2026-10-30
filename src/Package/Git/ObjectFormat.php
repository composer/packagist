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

namespace App\Package\Git;

/**
 * Hash algorithm of a repository, which determines object id length everywhere in the protocol
 */
enum ObjectFormat: string
{
    case Sha1 = 'sha1';
    case Sha256 = 'sha256';

    public static function fromCapability(?string $value): self
    {
        return match ($value) {
            null, '', 'sha1' => self::Sha1,
            'sha256' => self::Sha256,
            default => throw new UnsupportedRepositoryException('Unsupported git object format "'.$value.'"'),
        };
    }

    public function rawLength(): int
    {
        return match ($this) {
            self::Sha1 => 20,
            self::Sha256 => 32,
        };
    }

    public function hexLength(): int
    {
        return $this->rawLength() * 2;
    }

    public function isObjectId(string $value): bool
    {
        return \strlen($value) === $this->hexLength() && ctype_xdigit($value) && strtolower($value) === $value;
    }

    /**
     * @return non-empty-string the hex object id of the given object
     */
    public function hashObject(ObjectType $type, string $data): string
    {
        return hash($this->value, $type->label().' '.\strlen($data)."\0".$data);
    }

    public function hash(string $data, bool $binary = false): string
    {
        return hash($this->value, $data, $binary);
    }
}
