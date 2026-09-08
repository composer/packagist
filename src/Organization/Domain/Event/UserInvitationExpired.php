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
 * A pending invitation's expiry has passed; recorded lazily during link validation with a system actor.
 */
final readonly class UserInvitationExpired implements InvitationEvent
{
    public const OrganizationEventType TYPE = OrganizationEventType::UserInvitationExpired;

    public function __construct(
        public Ulid $invitationId,
        public string $email,
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
        return ['email' => $this->email];
    }

    /**
     * @param array<string, mixed> $payload
     */
    public static function fromPayload(Ulid $invitationId, array $payload): self
    {
        return new self($invitationId, (string) $payload['email']);
    }
}
