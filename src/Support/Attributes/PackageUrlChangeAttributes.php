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

use App\Entity\Package;
use App\Support\SupportRequestType;

/** A list, as an org rename breaks many packages at once and only one request per type may be open. */
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

    /** Replaces the package's existing entry, if any, so a refiled URL corrects the earlier one. */
    public function withChange(PackageUrlChange $change): self
    {
        $changes = [];
        $replaced = false;
        foreach ($this->changes as $existing) {
            if ($existing->packageName === $change->packageName) {
                $existing = $change;
                $replaced = true;
            }
            $changes[] = $existing;
        }

        return new self($replaced ? $changes : [...$changes, $change]);
    }

    public function changeFor(string $packageName): ?PackageUrlChange
    {
        foreach ($this->changes as $change) {
            if ($change->packageName === $packageName) {
                return $change;
            }
        }

        return null;
    }

    /**
     * Frozen targets count as not done: a remote id freeze survives the URL change.
     *
     * @param callable(string): ?Package $find
     */
    public function isFullyApplied(callable $find): bool
    {
        foreach ($this->changes as $change) {
            $target = $find($change->packageName);
            if ($target === null || $target->isFrozen() || $target->getRepository() !== $change->repository) {
                return false;
            }
        }

        return true;
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
