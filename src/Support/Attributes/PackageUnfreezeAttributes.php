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

use App\Support\SupportRequestType;

/**
 * Uses the same packageNames key as {@see PackageTransferAttributes}, which is what makes these
 * requests turn up in the queue's JSON_EXTRACT search without a second clause for them.
 */
final readonly class PackageUnfreezeAttributes implements SupportRequestAttributes
{
    /** @param list<string> $packageNames picked from the requester's own frozen packages, never typed */
    public function __construct(
        public array $packageNames,
    ) {
    }

    public function type(): SupportRequestType
    {
        return SupportRequestType::PackageUnfreeze;
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
