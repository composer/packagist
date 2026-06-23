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

enum SlugReservationKind: string
{
    /** Slug freed by a rename; the old slug redirects to the new one. */
    case RenamedFrom = 'renamed_from';

    /** Slug freed by a soft-delete; the old slug shows a gone page. */
    case Deleted = 'deleted';
}

/**
 * Blocks a slug freed by a rename or soft-delete. Slugs stay blocked indefinitely and
 * are released only by a packagist-admin (no auto-expiry / sweep). A reservation is
 * active while `releasedAt` is null.
 *
 * Reservations are created by the rename/soft-delete events, which will be done at a later
 * stage; this read model exists now so creation can already honour active reservations.
 */
#[ORM\Entity(repositoryClass: SlugReservationRepository::class)]
#[ORM\Table(name: 'slug_reservation')]
#[ORM\Index(name: 'slug_reservation_slug_idx', columns: ['slug'])]
#[ORM\Index(name: 'slug_reservation_org_idx', columns: ['orgId'])]
class SlugReservation
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $id,

        #[ORM\Column(length: Slug::MAX_LENGTH)]
        public readonly string $slug,

        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $orgId,

        #[ORM\Column(length: 16)]
        public readonly SlugReservationKind $kind,

        #[ORM\Column]
        public readonly \DateTimeImmutable $reservedAt,

        #[ORM\Column(nullable: true)]
        public readonly ?\DateTimeImmutable $releasedAt = null,

        /** Packagist-admin who released the slug. References fos_user.id. */
        #[ORM\Column(nullable: true)]
        public readonly ?int $releasedBy = null,
    ) {
    }

    public function isActive(): bool
    {
        return $this->releasedAt === null;
    }
}
