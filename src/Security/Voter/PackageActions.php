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

namespace App\Security\Voter;

enum PackageActions: string
{
    case Edit = 'edit';
    case AddMaintainer = 'add_maintainer';
    case RemoveMaintainer = 'remove_maintainer';
    case TransferPackage = 'transfer_package';
    case Abandon = 'abandon';
    case Delete = 'delete';
    case DeleteVersion = 'delete_version';
    case Update = 'update';
}
