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
 * The role under which a name is indexed in the audit_log_search table.
 *
 * Kept intentionally separate from the transparency-log filter roles: `user` covers the subject
 * user as well as maintainers and both sides of a username change, mirroring what the `user` filter
 * matches today.
 *
 * Names should be max 16 chars long
 */
enum AuditLogSearchType: string
{
    case User = 'user';
    case Actor = 'actor';
    case Package = 'package';
    case Organization = 'organization';
}
