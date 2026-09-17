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

use App\Support\PackageDisposition;

final readonly class AccountDeletionAttributes implements SupportRequestAttributes
{
    public function __construct(
        public PackageDisposition $packageDisposition,

        /** Username to hand the packages to. Only meaningful with PackageDisposition::Transfer. */
        public ?string $transferTo,
    ) {
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $transferTo = $data['transferTo'] ?? null;

        return new self(
            PackageDisposition::tryFrom((string) ($data['packageDisposition'] ?? '')) ?? PackageDisposition::Undecided,
            is_string($transferTo) && $transferTo !== '' ? $transferTo : null,
        );
    }

    public function toArray(): array
    {
        return [
            'packageDisposition' => $this->packageDisposition->value,
            'transferTo' => $this->transferTo,
        ];
    }

    public function summary(): string
    {
        if ($this->packageDisposition === PackageDisposition::Transfer && $this->transferTo !== null) {
            return $this->packageDisposition->label().' ('.$this->transferTo.')';
        }

        return $this->packageDisposition->label();
    }
}
