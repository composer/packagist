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

/**
 * Search index for the public transparency log. One row for each person and role that an entry names.
 *
 *  - `name` is the username at the time of the event. A username can change owner, so the User and
 *    Actor filters can find more than one account.
 *  - `userId` is for the link on a user's profile. It finds all entries of an account, also from before
 *    a rename.
 *  - `primaryRow` is set on one row per account and entry, so a search by account finds each entry once.
 *  - `datetime` and `leafIndex` are copies from the entry, so the indexes are in page order and MySQL
 *    does not have to sort all matches.
 *
 * Rows are written with raw SQL in {@see PackageTransparencyLogSearchRepository::index()}. This class is
 * only a mapping and is never instantiated.
 *
 * @see PackageTransparencyLog::getSearchTerms()
 */
#[ORM\Entity(repositoryClass: PackageTransparencyLogSearchRepository::class)]
#[ORM\Table(name: 'package_transparency_log_search')]
#[ORM\Index(name: 'type_name_datetime_idx', columns: ['type', 'name', 'datetime', 'leafIndex'])]
#[ORM\Index(name: 'user_id_primary_datetime_idx', columns: ['userId', 'primaryRow', 'datetime', 'leafIndex'])]
class PackageTransparencyLogSearch
{
    public function __construct(
        // the primary key makes it safe to index an entry again
        #[ORM\Id]
        #[ORM\Column(length: 16)]
        public readonly string $type,

        #[ORM\Id]
        #[ORM\Column(length: 255)]
        public readonly string $name,

        #[ORM\Id]
        #[ORM\Column(options: ['unsigned' => true])]
        public readonly int $leafIndex,

        #[ORM\Column]
        public readonly int $userId,

        #[ORM\Column]
        public readonly \DateTimeImmutable $datetime,

        #[ORM\Column]
        public readonly bool $primaryRow,
    ) {
    }
}
