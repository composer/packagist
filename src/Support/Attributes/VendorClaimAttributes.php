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

final readonly class VendorClaimAttributes implements SupportRequestAttributes
{
    public function __construct(
        public string $vendorName,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        return new self((string) ($data['vendorName'] ?? ''));
    }

    public function toArray(): array
    {
        return ['vendorName' => $this->vendorName];
    }

    public function summary(): string
    {
        return $this->vendorName;
    }
}
