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

use App\Organization\EventStore\OrganizationEventType;
use Symfony\Component\Uid\Ulid;

/**
 * The invitee accepts; the invitation is resolved. The org-side membership (team rows + the org member
 * record) is created by the companion {@see MemberJoined} event on the org stream, appended
 * in the same transaction. `teamIds` records the target teams that still existed at acceptance and that
 * the user was therefore added to.
 */
final readonly class UserInvitationAccepted implements InvitationEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::UserInvitationAccepted;

    /**
     * @param list<Ulid> $teamIds
     */
    public function __construct(
        public Ulid $invitationId,
        public string $email,
        public int $userId,
        public array $teamIds,
    ) {
    }

    public function aggregateId(): Ulid
    {
        return $this->invitationId;
    }

    public function eventType(): OrganizationEventType
    {
        return self::TYPE;
    }

    public function email(): string
    {
        return $this->email;
    }

    public function toPayload(): array
    {
        return [
            'email' => $this->email,
            'userId' => $this->userId,
            'teamIds' => array_map(static fn (Ulid $id): string => $id->toRfc4122(), $this->teamIds),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $invitationId, array $payload): self
    {
        /** @var list<string> $teamIds */
        $teamIds = $payload['teamIds'];

        return new self(
            $invitationId,
            (string) $payload['email'],
            (int) $payload['userId'],
            array_map(static fn (string $id): Ulid => Ulid::fromString($id), $teamIds),
        );
    }
}
