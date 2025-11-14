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

enum AbandonmentReason: string
{
    case Manual = 'manual';
    case RepositoryArchived = 'repository_archived';
    case ComposerJson = 'composer_json';
    case Both = 'both';
    case Unknown = 'unknown';
}
