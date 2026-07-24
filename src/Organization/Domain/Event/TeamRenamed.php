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
 * A custom team is renamed. The payload records only the changed field, as a from/to pair.
 */
final readonly class TeamRenamed implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::TeamRenamed;

    public function __construct(
        public Ulid $organizationId,
        public Ulid $teamId,
        public string $name,
        public string $previousName,
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
            'changes' => [
                'name' => ['from' => $this->previousName, 'to' => $this->name],
            ],
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        /** @var array{name: array{from: string, to: string}} $changes */
        $changes = $payload['changes'];

        return new self(
            $organizationId,
            Ulid::fromString((string) $payload['teamId']),
            (string) $changes['name']['to'],
            (string) $changes['name']['from'],
        );
    }
}
