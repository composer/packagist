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

        #[ORM\Column(length: 20)]
        public readonly string $slug,

        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $orgId,

        /** `renamed_from` (redirect) | `deleted` (gone page). */
        #[ORM\Column(length: 16)]
        public readonly string $kind,

        #[ORM\Column(type: 'datetime_immutable')]
        public readonly \DateTimeImmutable $reservedAt,

        #[ORM\Column(type: 'datetime_immutable', nullable: true)]
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
