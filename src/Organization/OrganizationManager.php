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

use App\Entity\User;
use App\Organization\Domain\DisplayName;
use App\Organization\Domain\Exception\InvalidDisplayNameException;
use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Exception\SlugTakenException;
use App\Organization\Domain\Organization;
use App\Organization\Domain\Slug;
use App\Organization\EventStore\Actor;
use App\Organization\EventStore\EventStore;
use App\Validator\NotReservedWord;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Symfony\Component\Uid\Ulid;

final class OrganizationManager
{
    public function __construct(
        private readonly EventStore $eventStore,
        private readonly OrganizationSlugClaimGuard $slugChecker,
    ) {
    }

    /**
     * @return Organization the created aggregate
     *
     * @throws InvalidSlugException
     * @throws InvalidDisplayNameException
     * @throws SlugTakenException
     */
    public function create(User $owner, string $slug, string $displayName, ?string $ip): Organization
    {
        $slug = Slug::fromUserInput($slug);
        $displayName = DisplayName::fromUserInput($displayName);

        $this->slugChecker->assertClaimable($slug, $owner);

        if (\in_array(mb_strtolower($displayName->value), NotReservedWord::RESERVED_WORDS, true)) {
            throw new InvalidDisplayNameException(sprintf('"%s" is a reserved name and cannot be used.', $displayName->value));
        }

        $organization = Organization::create(new Ulid(), $slug, $displayName);

        try {
            $this->eventStore->append($organization, Actor::owner($owner), $ip);
        } catch (UniqueConstraintViolationException $e) {
            throw new SlugTakenException(sprintf('The organization slug "%s" is already taken.', $slug->value), 0, $e);
        }

        return $organization;
    }
}
