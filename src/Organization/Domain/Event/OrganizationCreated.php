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
use Symfony\Component\Uid\Ulid;

/**
 * Creates the aggregate at sequence = 1; the creator becomes the owner.
 */
final readonly class OrganizationCreated implements DomainEvent
{
    public const string TYPE = 'organization-created';

    public function __construct(
        public Ulid $organizationId,
        public string $slug,
        public string $displayName,
        public ?string $avatarUrl,
    ) {
    }

    public function aggregateId(): Ulid
    {
        return $this->organizationId;
    }

    public function eventType(): string
    {
        return self::TYPE;
    }

    public function toPayload(): array
    {
        return [
            'slug' => $this->slug,
            'displayName' => $this->displayName,
            'avatarUrl' => $this->avatarUrl,
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
            isset($payload['avatarUrl']) ? (string) $payload['avatarUrl'] : null,
        );
    }
}
