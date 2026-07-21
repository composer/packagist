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
 * A user becomes a member of the organization. This is the org-level membership fact; the teams they
 * land in are recorded separately by the accompanying {@see TeamMemberAdded} events. Recorded in two
 * cases:
 *  - accepting an invitation: the org-stream half of the acceptance, appended to the org stream in the
 *    same transaction as the invitation stream's {@see UserInvitationAccepted} ($invitationId set);
 *  - creating the organization: the founding owner joining the new org ($invitationId null).
 */
final readonly class MemberJoined implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::MemberJoined;

    public function __construct(
        public Ulid $organizationId,
        public int $userId,
        public ?Ulid $invitationId,
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
            'userId' => $this->userId,
            'invitationId' => $this->invitationId?->toRfc4122(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        return new self(
            $organizationId,
            (int) $payload['userId'],
            isset($payload['invitationId']) ? Ulid::fromString((string) $payload['invitationId']) : null,
        );
    }
}
