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

enum SupportMessageVisibility: string
{
    /** Private admin note. Never emailed, never shown to the requester. */
    case Internal = 'internal';

    /** Emailed to the requester, and kept as the record of what was said. */
    case Reply = 'reply';
}

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
    #[ORM\Id]
    #[ORM\Column(type: 'ulid')]
    public readonly Ulid $id;

    #[ORM\Column]
    public readonly \DateTimeImmutable $createdAt;

    public function __construct(
        #[ORM\ManyToOne(targetEntity: SupportRequest::class, inversedBy: 'messages')]
        #[ORM\JoinColumn(name: 'requestId', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
        public readonly SupportRequest $request,

        #[ORM\Column(length: 16)]
        public readonly SupportMessageVisibility $visibility,

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

    public function isInternal(): bool
    {
        return $this->visibility === SupportMessageVisibility::Internal;
    }
}
