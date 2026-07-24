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

namespace App\Organization\Domain\Event;

use App\Organization\EventStore\DomainEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * Creates the aggregate at sequence = 1. It records the identity of the org and the ids of its two
 * system teams (so those ids are stable and the roles are unambiguous), but the teams themselves and
 * the creator's membership in each follow as their own TeamCreated / TeamMemberAdded events in the
 * same creation batch, keeping every projection a faithful replay of first-class facts.
 */
final readonly class OrganizationCreated implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::OrganizationCreated;

    public function __construct(
        public Ulid $organizationId,
        public string $slug,
        public string $displayName,
        public Ulid $ownersTeamId,
        public Ulid $allMembersTeamId,
    ) {
    }

    public function aggregateId(): Ulid
    {
        return $this->organizationId;
    }

    public function eventType(): OrganizationEventType
    {
        return self::TYPE;
    }

    public function toPayload(): array
    {
        return [
            'slug' => $this->slug,
            'displayName' => $this->displayName,
            'ownersTeamId' => $this->ownersTeamId->toRfc4122(),
            'allMembersTeamId' => $this->allMembersTeamId->toRfc4122(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        return new self(
            $organizationId,
            (string) $payload['slug'],
            (string) $payload['displayName'],
            Ulid::fromString((string) $payload['ownersTeamId']),
            Ulid::fromString((string) $payload['allMembersTeamId']),
        );
    }
}
