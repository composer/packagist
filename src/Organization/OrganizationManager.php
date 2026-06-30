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
use App\Entity\User;
use App\Organization\Domain\DisplayName;
use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Exception\SlugTakenException;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\Domain\Organization;
use App\Organization\Domain\Slug;
use App\Organization\EventStore\Actor;
use App\Organization\EventStore\EventStore;
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
     * @throws SlugTakenException
     */
    public function create(User $owner, string $slug, string $displayName, ?string $ip): Organization
    {
        $slug = new Slug($slug);
        $displayName = new DisplayName($displayName);

        $this->slugChecker->assertClaimable($slug, $owner);

        $organization = Organization::create(new Ulid(), $slug, $displayName);

        try {
            $this->eventStore->append($organization, Actor::owner($owner), $ip);
        } catch (UniqueConstraintViolationException $e) {
            throw new SlugTakenException(sprintf('The organization slug "%s" is already taken.', $slug->value), 0, $e);
        }

        return $organization;
    }

    /**
     * Only fields that actually change are recorded as events; an unchanged submission is a no-op.
     *
     * @throws InvalidSlugException
     * @throws SlugTakenException
     */
    public function edit(OrganizationReadModel $organization, User $actor, string $slug, string $displayName, ?string $ip): void
    {
        $newSlug = Slug::fromUserInput($slug);
        $newDisplayName = DisplayName::fromUserInput($displayName);

        $slugChanged = $newSlug->value !== $organization->slug;
        $displayNameChanged = $newDisplayName->value !== $organization->displayName;

        if (!$slugChanged && !$displayNameChanged) {
            return;
        }

        if ($slugChanged) {
            $this->slugChecker->assertClaimable($newSlug, $actor);
        }

        $aggregate = Organization::reconstitute(
            $organization->id,
            $this->eventStore->loadHistory($organization->id),
        );

        if ($displayNameChanged) {
            $aggregate->changeName($newDisplayName);
        }

        if ($slugChanged) {
            $aggregate->changeSlug($newSlug);
        }

        try {
            $this->eventStore->append($aggregate, $this->actorFor($actor, $organization), $ip);
        } catch (UniqueConstraintViolationException $e) {
            throw new SlugTakenException(sprintf('The organization slug "%s" is already taken.', $newSlug->value), 0, $e);
        }
    }

    private function actorFor(User $actor, OrganizationReadModel $organization): Actor
    {
        if ($organization->createdBy?->getId() === $actor->getId()) {
            return Actor::owner($actor);
        }

        return Actor::packagistAdmin($actor);
    }
}
