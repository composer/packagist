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

namespace App\Entity;

use App\Organization\Domain\InvitationStatus;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * Read-model projection of the Invitation aggregate. The invitation is a separate aggregate from the
 * org; its events live in the shared `organization_event` stream and are projected here by
 * {@see \App\Organization\Projection\InvitationReadModelProjector}.
 *
 * The invited email is stored so the read model and the owner's management view can show it, but it is
 * never written to the public transparency log. Only the token hash is stored; the raw token exists only
 * in the emailed link.
 *
 * @see \App\Organization\Domain\Invitation for the write-side aggregate.
 */
#[ORM\Entity(repositoryClass: OrganizationInvitationRepository::class)]
#[ORM\Table(name: 'organization_invitation')]
#[ORM\Index(name: 'org_invitation_org_idx', columns: ['orgId'])]
#[ORM\Index(name: 'org_invitation_pending_idx', columns: ['orgId', 'emailCanonical', 'status'])]
#[ORM\Index(name: 'org_invitation_expiry_idx', columns: ['status', 'expiresAt'])]
class OrganizationInvitation
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $id,

        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $orgId,

        #[ORM\Column(length: 255)]
        public readonly string $email,

        #[ORM\Column(length: 255)]
        public readonly string $emailCanonical,

        #[ORM\Column(length: 16)]
        public InvitationStatus $status,

        #[ORM\Column(length: 64)]
        public string $tokenHash,

        #[ORM\Column(type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,

        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $expiresAt,

        #[ORM\Column(type: 'datetime_immutable')]
        public \DateTimeImmutable $lastSentAt,

        /** Null once the inviting user is deleted. */
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'invitedByUserId', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        public readonly ?User $invitedBy,

        /** Set when the invitation leaves the pending state for any reason. */
        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
        public ?\DateTimeImmutable $resolvedAt = null,
    ) {
    }

    public function isPending(): bool
    {
        return $this->status === InvitationStatus::Pending;
    }

    /**
     * Whether the invitation is past its expiry, regardless of whether the status has been swept to
     * `expired` yet. Expiry is enforced lazily, so a still-`pending` row whose `expiresAt` has passed
     * is treated as expired by every freshness check.
     */
    public function isExpired(\DateTimeImmutable $now): bool
    {
        return $now > $this->expiresAt;
    }

    /**
     * A pending invitation that has not yet passed its expiry, i.e. one a valid link can still act on
     * and one that blocks a duplicate {@see \App\Organization\Domain\Event\UserInvitationSent}.
     */
    public function isActive(\DateTimeImmutable $now): bool
    {
        return $this->isPending() && !$this->isExpired($now);
    }

    /**
     * The status to present right now: `expired` for a pending row already past its expiry, since the
     * sweep to `expired` happens lazily. Otherwise the persisted status.
     */
    public function effectiveStatus(\DateTimeImmutable $now): InvitationStatus
    {
        if ($this->isPending() && $this->isExpired($now)) {
            return InvitationStatus::Expired;
        }

        return $this->status;
    }
}
