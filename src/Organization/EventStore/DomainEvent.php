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

namespace App\Organization\EventStore;

use Symfony\Component\Uid\Ulid;

interface DomainEvent
{
    public function aggregateId(): Ulid;

    /**
     * e.g. `organization-created`.
     */
    public function eventType(): string;

    /**
     * @return array<string, mixed>
     */
    public function toPayload(): array;
}
