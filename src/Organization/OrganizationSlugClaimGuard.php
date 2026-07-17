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

use App\Entity\Organization as OrganizationReadModel;
use App\Entity\OrganizationRepository;
use App\Entity\PackageRepository;
use App\Entity\SlugReservationRepository;
use App\Entity\User;
use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Exception\SlugTakenException;
use App\Organization\Domain\Slug;
use App\Validator\NotReservedWordValidator;

final class OrganizationSlugClaimGuard
{
    public function __construct(
        private readonly OrganizationRepository $organizationRepo,
        private readonly SlugReservationRepository $slugReservationRepo,
        private readonly PackageRepository $packageRepo,
    ) {
    }

    /**
     * @throws InvalidSlugException if the slug is reserved (deny-list) or collides with a vendor prefix the user cannot access
     * @throws SlugTakenException   if a live organization or active reservation already holds the slug
     */
    public function assertClaimable(Slug $slug, User $user, ?OrganizationReadModel $claimingOrg = null): void
    {
        if (\in_array($slug->value, NotReservedWordValidator::RESERVED_WORDS, true)) {
            throw new InvalidSlugException(sprintf('"%s" is a reserved name and cannot be used.', $slug->value));
        }

        // A slug that matches an existing vendor prefix may only be claimed by someone
        // who has access to that prefix (i.e. maintains a package under it).
        if ($this->packageRepo->isVendorTaken($slug->value, $user)) {
            throw new InvalidSlugException(sprintf('"%s" matches a vendor prefix you do not have access to.', $slug->value));
        }

        if ($this->organizationRepo->slugExists($slug->value) || $this->slugReservationRepo->isReserved($slug->value, $claimingOrg?->id)) {
            throw new SlugTakenException(sprintf('The organization slug "%s" is already taken.', $slug->value));
        }
    }
}
