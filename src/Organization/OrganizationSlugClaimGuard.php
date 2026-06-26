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

namespace App\Organization;

use App\Entity\OrganizationRepository;
use App\Entity\PackageRepository;
use App\Entity\SlugReservationRepository;
use App\Entity\User;
use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Exception\SlugTakenException;
use App\Organization\Domain\Slug;
use App\Validator\NotReservedWord;

/**
 * TODO a Levenshtein-similarity check against a protected-names should be added later
 */
final class OrganizationSlugClaimGuard
{
    public function __construct(
        private readonly OrganizationRepository $organizations,
        private readonly SlugReservationRepository $reservations,
        private readonly PackageRepository $packages,
    ) {
    }

    /**
     * @throws InvalidSlugException if the slug is reserved (deny-list) or collides with a vendor prefix the user cannot access
     * @throws SlugTakenException   if a live organization or active reservation already holds the slug
     */
    public function assertClaimable(Slug $slug, User $user): void
    {
        if (\in_array($slug->value, NotReservedWord::WORDS, true)) {
            throw new InvalidSlugException(sprintf('"%s" is a reserved name and cannot be used.', $slug->value));
        }

        // A slug that matches an existing vendor prefix may only be claimed by someone
        // who has access to that prefix (i.e. maintains a package under it).
        if ($this->packages->isVendorTaken($slug->value, $user)) {
            throw new InvalidSlugException(sprintf('"%s" matches a vendor prefix you do not have access to.', $slug->value));
        }

        if ($this->organizations->slugExists($slug->value) || $this->reservations->isReserved($slug->value)) {
            throw new SlugTakenException(sprintf('The organization slug "%s" is already taken.', $slug->value));
        }
    }
}
