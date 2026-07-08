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

/**
 * Base for event-sourced aggregates. Cross-row uniqueness (e.g. slug) is enforced by the projection.
 */
abstract class AbstractAggregate
{
    /** Number of events already persisted; the expected sequence for optimistic concurrency. */
    protected int $version = 0;

    /** @var list<DomainEvent> events recorded but not yet persisted */
    private array $pendingEvents = [];

    protected function __construct(public readonly Ulid $id)
    {
    }

    public function version(): int
    {
        return $this->version;
    }

    /**
     * @return list<DomainEvent>
     */
    public function pullPendingEvents(): array
    {
        $events = $this->pendingEvents;
        $this->pendingEvents = [];

        return $events;
    }

    protected function record(DomainEvent $event): void
    {
        $this->apply($event);
        $this->pendingEvents[] = $event;
    }

    /**
     * @param iterable<DomainEvent> $events
     */
    protected function replay(iterable $events): void
    {
        foreach ($events as $event) {
            $this->apply($event);
            $this->version++;
        }
    }

    abstract protected function apply(DomainEvent $event): void;
}
