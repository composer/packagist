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
 * A list rather than a single pair: one GitHub org rename breaks every package under it at once, and
 * support_request_open_uniq allows the requester only one open request per type, so a second filing
 * appends here instead of being turned away.
 */
final readonly class PackageUrlChangeAttributes implements SupportRequestAttributes
{
    /** @param list<PackageUrlChange> $changes */
    public function __construct(
        public array $changes,
    ) {
    }

    public function type(): SupportRequestType
    {
        return SupportRequestType::PackageUrlChange;
    }

    /** @param array<string, mixed> $data */
    public static function fromArray(array $data): self
    {
        $rows = $data['changes'] ?? [];
        if (!is_array($rows)) {
            return new self([]);
        }

        $changes = [];
        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }
            $changes[] = new PackageUrlChange((string) ($row['packageName'] ?? ''), (string) ($row['repository'] ?? ''));
        }

        return new self($changes);
    }

    public function toArray(): array
    {
        return ['changes' => array_map(
            static fn (PackageUrlChange $change): array => ['packageName' => $change->packageName, 'repository' => $change->repository],
            $this->changes,
        )];
    }

    public function withChange(PackageUrlChange $change): self
    {
        return new self([...$this->changes, $change]);
    }

    /** @return list<string> */
    public function names(): array
    {
        return array_map(static fn (PackageUrlChange $change): string => $change->packageName, $this->changes);
    }

    public function summary(): string
    {
        $first = $this->changes[0] ?? null;
        if ($first === null) {
            return '';
        }

        $rest = count($this->changes) - 1;

        return $first->packageName.' → '.$first->repository.($rest > 0 ? ' and '.$rest.' more' : '');
    }
}
