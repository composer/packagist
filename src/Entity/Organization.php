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

use App\Organization\Domain\Slug;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

enum OrganizationStatus: string
{
    case Active = 'active';
    // Groundwork for org deletion (not yet implemented).
    case Deleted = 'deleted';
}

/**
 * Read-model projection of the Organization aggregate.
 *
 * @see \App\Organization\Domain\Organization for the write-side aggregate.
 */
#[ORM\Entity(repositoryClass: OrganizationRepository::class)]
#[ORM\Table(name: 'organization')]
#[ORM\UniqueConstraint(name: 'org_slug_idx', columns: ['slug'])]
#[ORM\Index(name: 'org_created_by_idx', columns: ['createdBy'])]
class Organization
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $id,

        #[ORM\Column(length: Slug::MAX_LENGTH)]
        public readonly string $slug,

        #[ORM\Column(length: 60)]
        public readonly string $displayName,

        #[ORM\Column(length: 16)]
        public readonly OrganizationStatus $status,

        #[ORM\Column(type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,

        /** Owner by virtue of creation until the membership stage ships. Null once the creating user is deleted, or for system/automation-created orgs. */
        #[ORM\ManyToOne(targetEntity: User::class)]
        #[ORM\JoinColumn(name: 'createdBy', referencedColumnName: 'id', nullable: true, onDelete: 'SET NULL')]
        public readonly ?User $createdBy,

        // Groundwork for org deletion (not yet implemented)
        #[ORM\Column(nullable: true)]
        public readonly ?\DateTimeImmutable $deletedAt = null,

        /** `owner` | `packagist-admin` — who triggered the soft-delete. */
        #[ORM\Column(length: 32, nullable: true)]
        public readonly ?string $deletedReason = null,
    ) {
    }

    public function isDeleted(): bool
    {
        return $this->deletedAt !== null;
    }
}
