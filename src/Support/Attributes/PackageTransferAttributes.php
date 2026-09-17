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

namespace App\Support\Attributes;

final readonly class PackageTransferAttributes implements SupportRequestAttributes
{
    /** @param list<string> $packageNames as the requester typed them, already trimmed and de-blanked */
    public function __construct(
        public array $packageNames,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $names = $data['packageNames'] ?? [];

        return new self(is_array($names) ? array_values(array_map(strval(...), $names)) : []);
    }

    public function toArray(): array
    {
        return ['packageNames' => $this->packageNames];
    }

    public function summary(): string
    {
        return implode(', ', $this->packageNames);
    }
}
