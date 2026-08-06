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
 * Creates the invitation aggregate: an owner invites an email address to one or more teams. The
 * aggregate id is the invitation's own ULID. The token hash is carried so the projection can store it;
 * the raw token exists only in the emailed link and is never persisted.
 */
final readonly class UserInvitationSent implements InvitationEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::UserInvitationSent;

    /**
     * @param list<Ulid> $teamIds
     */
    public function __construct(
        public Ulid $invitationId,
        public Ulid $organizationId,
        public string $email,
        public string $emailCanonical,
        public array $teamIds,
        public string $tokenHash,
        public \DateTimeImmutable $expiresAt,
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
            'organizationId' => $this->organizationId->toRfc4122(),
            'email' => $this->email,
            'emailCanonical' => $this->emailCanonical,
            'teamIds' => array_map(static fn (Ulid $id): string => $id->toRfc4122(), $this->teamIds),
            'tokenHash' => $this->tokenHash,
            'expiresAt' => $this->expiresAt->format(DATE_ATOM),
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
            Ulid::fromString((string) $payload['organizationId']),
            (string) $payload['email'],
            (string) $payload['emailCanonical'],
            array_map(static fn (string $id): Ulid => Ulid::fromString($id), $teamIds),
            (string) $payload['tokenHash'],
            new \DateTimeImmutable((string) $payload['expiresAt']),
        );
    }
}
