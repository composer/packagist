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

namespace App\Audit;

/**
 * A version that has been soft-deleted with one of these reasons is a stable, published snapshot
 * whose identity (source/dist reference) is frozen. Hard deletion of such a row is NOT and MUST NOT
 * be supported — not via the UI, not via admin tooling, not via CLI commands.
 *
 * The reason is structural: hard-deleting a stable version frees its (package, version-string) slot
 * and would let a subsequent crawl create a new row at the same coordinates with different content,
 * breaking the immutability guarantee that downstream tooling (Composer's lock-file pinning,
 * security scanners, mirrors) relies on. Soft-deleted rows therefore persist indefinitely as
 * immutable historical records; the V2 dump filters them out, but the database row stays.
 *
 * Dev versions (branches like `dev-main`) are NOT subject to this rule — they are mutable trackers
 * by design, never carry a reason from this enum during normal operation (apart from
 * AutoDeletedMissing while the branch is temporarily gone), and may be hard-deleted by the Updater
 * housekeeping or the per-version UI delete. The auto-purge in Updater::update() only fires on
 * dev rows; stable AutoDeletedMissing rows stay until they reappear upstream and auto-recover.
 *
 * Whole-package deletion (PackageManager::deletePackage / CleanSpamPackagesCommand) is a separate
 * concept — the entire package goes away, versions cascade with it — and is out of scope here.
 */
enum VersionDeletionReason: string
{
    /**
     * Auto-soft-deleted by the Updater when a version disappears from upstream.
     * - Metadata: filtered out of V2 dumps.
     * - Page: shown grayed-out to every visitor.
     * - Recovery: anyone with DeleteVersion permission; the Updater also auto-recovers on
     *   reappearance.
     */
    case AutoDeletedMissing = 'auto_missing';

    /**
     * Maintainer pulled the version via the UI.
     * - Metadata: filtered out of V2 dumps.
     * - Page: shown grayed-out to every visitor.
     * - Recovery: maintainer or admin. The Updater never recreates it.
     */
    case DeletedByMaintainer = 'maintainer';

    /**
     * Admin pulled the version (e.g. bad release, supply-chain concern).
     * - Metadata: filtered out of V2 dumps.
     * - Page: shown grayed-out to every visitor (with reason text if provided).
     * - Recovery: admin only. The Updater never recreates it.
     */
    case DeletedByAdmin = 'admin';

    /**
     * Admin took the version down with no public trace — used by the spam-cascade flow
     * (reason text 'spam') and for selective per-version takedowns where the public should
     * not see the row at all. The admin is prompted for a reason text in the per-version UI.
     * - Metadata: filtered out of V2 dumps.
     * - Page: hidden from non-maintainers; shown grayed-out to maintainers and admins.
     * - Recovery: admin only. Unfreezing a spam-frozen package also bulk-recovers Hidden
     *   versions of that package.
     */
    case Hidden = 'hidden';

    public function isRecoverableByMaintainer(): bool
    {
        return match ($this) {
            self::DeletedByMaintainer, self::AutoDeletedMissing => true,
            self::DeletedByAdmin, self::Hidden => false,
        };
    }

    /**
     * Whether the soft-deleted version should still appear on the package page for the general
     * public. Maintainers and admins see all soft-deleted versions regardless.
     */
    public function isVisibleToPublic(): bool
    {
        return $this !== self::Hidden;
    }
}
