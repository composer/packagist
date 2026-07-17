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

use App\Entity\OrganizationEvent;
use App\Entity\OrganizationEventRepository;
use App\Organization\Projection\Projector;
use App\Util\DoctrineTrait;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\ORM\EntityManagerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * Append → project → commit all happen in one DB transaction. Optimistic concurrency is
 * enforced by the unique (aggregateId, sequence) constraint.
 */
final readonly class EventStore
{
    use DoctrineTrait;

    /**
     * @param iterable<Projector> $projectors
     */
    public function __construct(
        private ManagerRegistry $doctrine,
        private OrganizationEventRepository $events,
        private iterable $projectors,
    ) {
    }

    /**
     * Persist the aggregate's pending events and project them in the same transaction.
     *
     * @throws ConcurrencyException               on an (aggregateId, sequence) conflict; reload and retry
     * @throws UniqueConstraintViolationException on a projection uniqueness conflict (e.g. slug)
     */
    public function append(AbstractAggregate $aggregate, Actor $actor, ?string $ip): void
    {
        $this->appendAll([$aggregate], $actor, $ip);
    }

    /**
     * Persist the pending events of several aggregates and project them all in one transaction. Use this
     * for a command that must atomically touch more than one stream (e.g. accepting an invitation, which
     * resolves the invitation aggregate and adds the org membership together). Each aggregate keeps its
     * own sequence; events are appended in the given aggregate order.
     *
     * @param iterable<AbstractAggregate> $aggregates
     *
     * @throws ConcurrencyException               on an (aggregateId, sequence) conflict; reload and retry
     * @throws UniqueConstraintViolationException on a projection uniqueness conflict (e.g. slug)
     */
    public function appendAll(iterable $aggregates, Actor $actor, ?string $ip): void
    {
        /** @var list<array{aggregate: AbstractAggregate, sequence: int, event: DomainEvent}> $pending */
        $pending = [];
        foreach ($aggregates as $aggregate) {
            $sequence = $aggregate->version();
            foreach ($aggregate->pullPendingEvents() as $event) {
                $pending[] = ['aggregate' => $aggregate, 'sequence' => ++$sequence, 'event' => $event];
            }
        }

        if ($pending === []) {
            return;
        }

        $now = new \DateTimeImmutable();

        try {
            // Open the transaction on the connection rather than via EntityManager::wrapInTransaction():
            // a connection-level rollback is independent of whether the failing flush closed the EM.
            $this->getEM()->wrapInTransaction(function (EntityManagerInterface $em) use ($pending, $actor, $ip, $now): void {
                foreach ($pending as $item) {
                    $event = $item['event'];

                    $stored = new OrganizationEvent(
                        new Ulid(),
                        $item['aggregate']->id,
                        $item['sequence'],
                        $event->eventType(),
                        $event->toPayload(),
                        $actor->label->value,
                        $now,
                        $actor->userId,
                        $ip,
                    );

                    // Flush each event before projecting so the (aggregateId, sequence) constraint
                    // is enforced at append time and projectors run against a persisted event.
                    $em->persist($stored);
                    $em->flush();

                    $recorded = new RecordedEvent($stored->id, $event, $item['sequence'], $actor, $now, $ip);
                    foreach ($this->projectors as $projector) {
                        $projector->project($recorded);
                    }
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->doctrine->resetManager();

            if (str_contains($e->getMessage(), 'org_event_seq_idx')) {
                throw new ConcurrencyException('Concurrent modification of an organization aggregate.', 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Load the persisted events for an aggregate, oldest first.
     *
     * @return list<array{type: OrganizationEventType, payload: array<string, mixed>}>
     */
    public function loadHistory(Ulid $aggregateId): array
    {
        $events = $this->events->findBy(['aggregateId' => $aggregateId], ['sequence' => 'ASC']);

        return array_values(array_map(static fn (OrganizationEvent $event): array => [
            'type' => $event->type,
            'payload' => $event->payload,
        ], $events));
    }
}
