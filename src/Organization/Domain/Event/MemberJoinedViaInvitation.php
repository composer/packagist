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
 * The org-stream half of accepting an invitation: a new member joins, added to the given teams (the
 * still-existing target teams plus the automatically-managed `all organization members` team). Appended
 * to the org stream in the same transaction as the invitation stream's
 * {@see UserInvitationAccepted}.
 *
 * Unlike the owner-driven {@see TeamMemberAdded}, this event carries the whole team set for the join and
 * is the single event the transparency log publishes for it ("{user} joined ... via invitation"), so the
 * accompanying per-team adds are not separately logged.
 */
final readonly class MemberJoinedViaInvitation implements DomainEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::MemberJoinedViaInvitation;

    /**
     * @param list<Ulid> $teamIds the teams the user is added to (target teams + the all-members team)
     */
    public function __construct(
        public Ulid $organizationId,
        public int $userId,
        public array $teamIds,
        public Ulid $invitationId,
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
            'teamIds' => array_map(static fn (Ulid $id): string => $id->toRfc4122(), $this->teamIds),
            'invitationId' => $this->invitationId->toRfc4122(),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $organizationId, array $payload): self
    {
        /** @var list<string> $teamIds */
        $teamIds = $payload['teamIds'];

        return new self(
            $organizationId,
            (int) $payload['userId'],
            array_map(static fn (string $id): Ulid => Ulid::fromString($id), $teamIds),
            Ulid::fromString((string) $payload['invitationId']),
        );
    }
}
