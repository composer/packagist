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
 * A custom team is deleted; its memberships cascade. Any user left in zero teams as a result
 * is no longer an org member. `name` is carried for the transparency log.
 */
final readonly class TeamDeleted implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::TeamDeleted;

    public function __construct(
        public Ulid $organizationId,
        public Ulid $teamId,
        public string $name,
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
            'teamId' => $this->teamId->toRfc4122(),
            'name' => $this->name,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        return new self(
            $organizationId,
            Ulid::fromString((string) $payload['teamId']),
            (string) $payload['name'],
        );
    }
}
