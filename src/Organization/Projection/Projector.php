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

namespace App\Organization\Projection;

use App\Organization\EventStore\RecordedEvent;

/**
 * Runs synchronously inside the same transaction as the event append (append → project → commit),
 * so there is no eventual consistency.
 */
interface Projector
{
    public function project(RecordedEvent $recorded): void;
}
