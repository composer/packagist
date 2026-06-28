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

namespace App\Organization\Domain;

use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Event\OrganizationRenamed;
use App\Organization\Domain\Event\OrganizationSlugChanged;
use App\Organization\EventStore\AbstractAggregate;
use App\Organization\EventStore\DomainEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * The Organization aggregate. External facts (slug uniqueness, deny-list, vendor collision,
 * rate limits) are checked by the application service before a command reaches here.
 *
 * This is the write-side model. The projection is {@see \App\Entity\Organization}.
 */
final class Organization extends AbstractAggregate
{
    private string $slug;

    private string $displayName;

    // Groundwork for org deletion (not yet implemented).
    private bool $deleted = false;

    public static function create(Ulid $id, Slug $slug, DisplayName $displayName): self
    {
        $organization = new self($id);
        $organization->record(new OrganizationCreated($id, $slug->value, $displayName->value));

        return $organization;
    }

    /**
     * Change the display name. No-op when the name is unchanged.
     */
    public function rename(DisplayName $displayName): void
    {
        if ($this->displayName === $displayName->value) {
            return;
        }

        $this->record(new OrganizationRenamed($this->id, $displayName->value, $this->displayName));
    }

    public function changeSlug(Slug $slug): void
    {
        if ($this->slug === $slug->value) {
            return;
        }

        $this->record(new OrganizationSlugChanged($this->id, $slug->value, $this->slug));
    }

    /**
     * @param list<array{type: OrganizationEventType, payload: array<string, mixed>}> $history
     */
    public static function reconstitute(Ulid $id, array $history): self
    {
        $organization = new self($id);
        $organization->replay(array_map(
            static fn (array $row): DomainEvent => self::denormalize($id, $row['type'], $row['payload']),
            $history,
        ));

        return $organization;
    }

    public function slug(): string
    {
        return $this->slug;
    }

    public function displayName(): string
    {
        return $this->displayName;
    }

    public function isDeleted(): bool
    {
        return $this->deleted;
    }

    protected function apply(DomainEvent $event): void
    {
        match (true) {
            $event instanceof OrganizationCreated => $this->applyCreated($event),
            $event instanceof OrganizationRenamed => $this->displayName = $event->displayName,
            $event instanceof OrganizationSlugChanged => $this->slug = $event->slug,
            default => throw new \LogicException('Unhandled organization event: '.$event->eventType()->value),
        };
    }

    private function applyCreated(OrganizationCreated $event): void
    {
        $this->slug = $event->slug;
        $this->displayName = $event->displayName;
        $this->deleted = false;
    }

    /**
     * @param array<string, mixed> $payload
     */
    private static function denormalize(Ulid $id, OrganizationEventType $type, array $payload): DomainEvent
    {
        return match ($type) {
            OrganizationEventType::OrganizationCreated => OrganizationCreated::fromPayload($id, $payload),
            OrganizationEventType::OrganizationRenamed => OrganizationRenamed::fromPayload($id, $payload),
            OrganizationEventType::OrganizationSlugChanged => OrganizationSlugChanged::fromPayload($id, $payload),
        };
    }
}
