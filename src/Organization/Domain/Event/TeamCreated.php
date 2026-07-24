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

use App\Organization\Domain\OrganizationTeamKind;
use App\Organization\EventStore\DomainEvent;
use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * A team is created within the org. User-created teams are {@see OrganizationTeamKind::Custom}; the two system
 * teams (`owners` and `all organization members`) are recorded with {@see OrganizationTeamKind::System} as part
 * of the creation batch that {@see OrganizationCreated} opens. `kind` distinguishes the two so the
 * protection guards know which teams cannot be renamed or deleted.
 */
final readonly class TeamCreated implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::TeamCreated;

    public function __construct(
        public Ulid $organizationId,
        public Ulid $teamId,
        public string $name,
        public OrganizationTeamKind $kind = OrganizationTeamKind::Custom,
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
            'kind' => $this->kind->value,
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
            OrganizationTeamKind::from((string) ($payload['kind'] ?? OrganizationTeamKind::Custom->value)),
        );
    }
}
