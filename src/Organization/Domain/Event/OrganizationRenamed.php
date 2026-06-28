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

final readonly class OrganizationRenamed implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::OrganizationRenamed;

    public function __construct(
        public Ulid $organizationId,
        public string $displayName,
        public string $previousDisplayName,
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
            'displayName' => $this->displayName,
            'previousDisplayName' => $this->previousDisplayName,
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        return new self(
            $organizationId,
            (string) $payload['displayName'],
            (string) $payload['previousDisplayName'],
        );
    }
}
