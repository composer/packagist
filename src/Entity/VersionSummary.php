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
 * The subset of a version that the version list, the sort comparator and the deletion-title
 * filter need. Implemented by the full Version entity and by VersionListItem, so read-only
 * pages can render a package's versions without hydrating every JSON blob.
 */
interface VersionSummary
{
    public function getId(): int;

    public function getPackage(): Package;

    public function getVersion(): string;

    public function getNormalizedVersion(): string;

    public function getMajorVersion(): int;

    /**
     * @return array<mixed>
     */
    public function getExtra(): array;

    public function hasVersionAlias(): bool;

    public function getVersionAlias(): string;

    public function isDevelopment(): bool;

    public function isDefaultBranch(): bool;

    public function getReleasedAt(): ?\DateTimeImmutable;

    public function isSoftDeleted(): bool;

    public function getSoftDeletedAt(): ?\DateTimeImmutable;

    public function getDeletionReason(): ?VersionDeletionReason;

    public function getDeletionReasonText(): ?string;

    public function getInternalDeletionReasonText(): ?string;

    /**
     * Human-readable tooltip/title for a soft-deleted version, or null if the version is not soft-deleted.
     *
     * @param bool $includeInternalReason Whether the viewer may see the admin-only internal reason.
     *                                    Must be false for any public output.
     */
    public function getDeletionTitle(bool $includeInternalReason = false): ?string;

    public function getLastBlockedReference(): ?string;
}
