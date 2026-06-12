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

final readonly class RecordedEvent
{
    public function __construct(
        public Ulid $eventId,
        public DomainEvent $event,
        public int $sequence,
        public Actor $actor,
        public \DateTimeImmutable $occurredAt,
        public ?string $ip,
    ) {
    }
}
