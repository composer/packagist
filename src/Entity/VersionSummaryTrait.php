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
use Composer\Package\Version\VersionParser;
use Composer\Pcre\Preg;

/**
 * VersionSummary members derived from other members, shared by Version and VersionListItem.
 * Written against the interface getters only, so it works on both.
 */
trait VersionSummaryTrait
{
    public function getMajorVersion(): int
    {
        return (int) explode('.', $this->getNormalizedVersion(), 2)[0];
    }

    public function hasVersionAlias(): bool
    {
        return $this->isDevelopment() && $this->getVersionAlias();
    }

    public function getVersionAlias(): string
    {
        $extra = $this->getExtra();

        if (isset($extra['branch-alias'][$this->getVersion()])) {
            $parser = new VersionParser();
            $version = $parser->normalizeBranch(str_replace('-dev', '', $extra['branch-alias'][$this->getVersion()]));

            return Preg::replace('{(\.9{7})+}', '.x', $version);
        }

        return '';
    }

    public function getRequireVersionAlias(): string
    {
        return str_replace('.x-dev', '.*@dev', $this->getVersionAlias());
    }

    /**
     * Human-readable tooltip/title for a soft-deleted version, or null if the version is not soft-deleted.
     *
     * @param bool $includeInternalReason Whether the viewer may see the admin-only internal reason.
     *                                    Must be false for any public output.
     */
    public function getDeletionTitle(bool $includeInternalReason = false): ?string
    {
        $softDeletedAt = $this->getSoftDeletedAt();
        if ($softDeletedAt === null) {
            return null;
        }

        $date = $softDeletedAt->format('Y-m-d H:i:s').' UTC';
        $reasonText = $this->getDeletionReasonText() !== null && $this->getDeletionReasonText() !== ''
            ? ': '.$this->getDeletionReasonText()
            : '';

        return match ($this->getDeletionReason()) {
            VersionDeletionReason::DeletedByMaintainer => 'Deleted by maintainer on '.$date,
            VersionDeletionReason::DeletedByAdmin => 'Removed by admin on '.$date.$reasonText
                .($includeInternalReason && $this->getInternalDeletionReasonText() !== null && $this->getInternalDeletionReasonText() !== ''
                    ? ' (Internal reason: '.$this->getInternalDeletionReasonText().')'
                    : ''),
            VersionDeletionReason::AutoDeletedMissing => 'No longer found in upstream repository',
            VersionDeletionReason::Hidden => 'Hidden by admin on '.$date.$reasonText,
            default => 'Deleted', // null reason or any future case → matches the templates' initial fallback
        };
    }

    public function isSoftDeleted(): bool
    {
        return $this->getSoftDeletedAt() !== null;
    }
}
