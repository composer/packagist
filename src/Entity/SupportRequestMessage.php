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

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * An internal note or a reply sent to the requester, both kept on the same thread.
 *
 * Unlike the usual rule that who-did-what belongs in the audit log, the author is stored here: a
 * message is authored content rather than mutation metadata, and its body is private, so the
 * publicly readable transparency log cannot carry it.
 */
#[ORM\Entity]
#[ORM\Table(name: 'support_request_message')]
#[ORM\Index(name: 'support_request_message_thread_idx', columns: ['requestId', 'createdAt'])]
class SupportRequestMessage
{
    /**
     * Opens the note written when an owner disputes a request we had already actioned. Matched to
     * keep that escalation to one per request, so reworking the wording below cannot silently re-arm
     * it.
     */
    public const DISPUTE_NOTE_PREFIX = 'DISPUTED:';

    #[ORM\Id]
    #[ORM\Column(type: 'ulid')]
    public readonly Ulid $id;

    #[ORM\Column]
    public readonly \DateTimeImmutable $createdAt;

    private function __construct(
        #[ORM\ManyToOne(targetEntity: SupportRequest::class, inversedBy: 'messages')]
        #[ORM\JoinColumn(name: 'requestId', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public readonly SupportRequest $request,

        /** Internal notes stay in the admin panel; everything else is emailed to the requester. */
        #[ORM\Column]
        public readonly bool $internal,

        #[ORM\Column(type: 'text')]
        public readonly string $contents,

        /** Null once the admin who wrote this is deleted. */
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'authorId', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        public readonly ?User $author,
    ) {
        $this->id = new Ulid();
        $this->createdAt = new \DateTimeImmutable();
    }

    /** A private admin note: never emailed, never shown to the requester. */
    public static function internalNote(SupportRequest $request, string $contents, ?User $author): self
    {
        return new self($request, true, $contents, $author);
    }

    /** Emailed to the requester by the caller, and kept here as the record of what was said. */
    public static function reply(SupportRequest $request, string $contents, ?User $author): self
    {
        return new self($request, false, $contents, $author);
    }
}
