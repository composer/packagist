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
class Organization
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $id,

        #[ORM\Column(length: Slug::MAX_LENGTH)]
        public string $slug,

        #[ORM\Column(length: 60)]
        public string $displayName,

        #[ORM\Column(length: 16)]
        public readonly OrganizationStatus $status,

        #[ORM\Column(type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $createdAt,

        /** The bootstrapped system `owners` team. Set at creation; the owner check is a membership lookup against it. */
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $ownersTeamId,

        /** The bootstrapped system `all organization members` team. Every org member belongs to it; its roster is managed automatically. */
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $allMembersTeamId,

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
