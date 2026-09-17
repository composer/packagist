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

namespace App\Entity;

use App\Audit\VersionDeletionReason;

/**
 * A version as the version list needs it: no JSON blobs, no ORM identity map, no change
 * tracking. Not a mapped entity — see VersionRepository::getVersionListForPackage().
 *
 * Accessors rather than public properties because VersionSummary has to be satisfied by the
 * Version entity too, which keeps its state private.
 */
final class VersionListItem implements VersionSummary
{
    use VersionSummaryTrait;

    /**
     * @param array<mixed> $extra
     */
    public function __construct(
        private readonly int $id,
        private readonly Package $package,
        private readonly string $version,
        private readonly string $normalizedVersion,
        private readonly bool $development,
        private readonly bool $isDefaultBranch,
        private readonly ?\DateTimeImmutable $releasedAt,
        private readonly array $extra,
        private readonly ?\DateTimeImmutable $softDeletedAt,
        private readonly ?VersionDeletionReason $deletionReason,
        private readonly ?string $deletionReasonText,
        private readonly ?string $internalDeletionReasonText,
        private readonly ?string $lastBlockedReference,
    ) {
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getPackage(): Package
    {
        return $this->package;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getNormalizedVersion(): string
    {
        return $this->normalizedVersion;
    }

    /**
     * @return array<mixed>
     */
    public function getExtra(): array
    {
        return $this->extra;
    }

    public function isDevelopment(): bool
    {
        return $this->development;
    }

    public function isDefaultBranch(): bool
    {
        return $this->isDefaultBranch;
    }

    public function getReleasedAt(): ?\DateTimeImmutable
    {
        return $this->releasedAt;
    }

    public function getSoftDeletedAt(): ?\DateTimeImmutable
    {
        return $this->softDeletedAt;
    }

    public function getDeletionReason(): ?VersionDeletionReason
    {
        return $this->deletionReason;
    }

    public function getDeletionReasonText(): ?string
    {
        return $this->deletionReasonText;
    }

    public function getInternalDeletionReasonText(): ?string
    {
        return $this->internalDeletionReasonText;
    }

    public function getLastBlockedReference(): ?string
    {
        return $this->lastBlockedReference;
    }

    public function __toString(): string
    {
        return $this->package->getName().' '.$this->version.' ('.$this->normalizedVersion.')';
    }
}
