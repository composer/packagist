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
 * Previous slug is reserved by the projection so it cannot be re-claimed.
 */
final readonly class OrganizationSlugChanged implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::OrganizationSlugChanged;

    public function __construct(
        public Ulid $organizationId,
        public string $slug,
        public string $previousSlug,
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
            'previousSlug' => $this->previousSlug,
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
            (string) $payload['previousSlug'],
        );
    }
}
