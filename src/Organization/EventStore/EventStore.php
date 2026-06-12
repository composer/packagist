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
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Uid\Ulid;

/**
 * Append → project → commit all happen in one DB transaction. Optimistic concurrency is
 * enforced by the unique (aggregateId, sequence) constraint.
 */
final class EventStore
{
    use DoctrineTrait;

    /**
     * @param iterable<Projector> $projectors
     */
    public function __construct(
        private readonly ManagerRegistry $doctrine,
        private readonly OrganizationEventRepository $events,
        private readonly iterable $projectors,
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
        $events = $aggregate->pullPendingEvents();
        if ($events === []) {
            return;
        }

        $expectedVersion = $aggregate->version();
        $now = new \DateTimeImmutable();

        $em = $this->getEM();

        try {
            // Open the transaction on the connection rather than via EntityManager::wrapInTransaction():
            // a connection-level rollback is independent of whether the failing flush closed the EM.
            $em->getConnection()->transactional(function () use ($em, $events, $expectedVersion, $aggregate, $actor, $ip, $now): void {
                $sequence = $expectedVersion;
                foreach ($events as $event) {
                    ++$sequence;

                    $stored = new OrganizationEvent(
                        new Ulid(),
                        $aggregate->id,
                        $sequence,
                        $event->eventType(),
                        $event->toPayload(),
                        $actor->label->value,
                        $now,
                        $actor->userId,
                        $actor->roleInOrg?->value,
                        $ip,
                    );

                    // Flush each event before projecting so the (aggregateId, sequence) constraint
                    // is enforced at append time and projectors run against a persisted event.
                    $em->persist($stored);
                    $em->flush();

                    $recorded = new RecordedEvent($stored->id, $event, $sequence, $actor, $now, $ip);
                    foreach ($this->projectors as $projector) {
                        $projector->project($recorded);
                    }
                }
            });
        } catch (UniqueConstraintViolationException $e) {
            $this->doctrine->resetManager();

            if (str_contains($e->getMessage(), 'org_event_seq_idx')) {
                throw new ConcurrencyException('Concurrent modification of aggregate '.$aggregate->id->toRfc4122().'.', 0, $e);
            }

            throw $e;
        }
    }

    /**
     * Load the persisted events for an aggregate, oldest first.
     *
     * @return list<array{type: string, payload: array<string, mixed>}>
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
