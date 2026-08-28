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
 * Bumps a pending invitation's expiry and re-issues its link. A fresh token hash overwrites the stored
 * one, so the previous link's token no longer matches and is dead immediately.
 */
final readonly class UserInvitationResent implements InvitationEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::UserInvitationResent;

    public function __construct(
        public Ulid $invitationId,
        public string $email,
        public string $tokenHash,
        public \DateTimeImmutable $newExpiresAt,
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
            'tokenHash' => $this->tokenHash,
            'newExpiresAt' => $this->newExpiresAt->format(DATE_ATOM),
        ];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $invitationId, array $payload): self
    {
        return new self(
            $invitationId,
            (string) $payload['email'],
            (string) $payload['tokenHash'],
            new \DateTimeImmutable((string) $payload['newExpiresAt']),
        );
    }
}
