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

namespace App\Package;

enum BranchDeletionOutcome: string
{
    /** The dev version tracking the branch was hard-deleted. */
    case Removed = 'removed';

    /** No version tracked the branch, or it was pulled by an admin/maintainer and stays as is. */
    case NothingToRemove = 'nothing_to_remove';

    /** Could not be applied directly, a regular update is needed. */
    case Deferred = 'deferred';
}
