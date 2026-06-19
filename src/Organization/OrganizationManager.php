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
use App\Organization\Domain\Exception\InvalidDisplayNameException;
use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Exception\RateLimitReachedException;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\Domain\Exception\SlugTakenException;
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
        private readonly OrganizationSlugChecker $slugChecker,
        private readonly OrganizationCreationRateLimiter $rateLimiter,
    ) {
    }

    /**
     * @return Organization the created aggregate
     *
     * @throws TwoFactorRequiredException
     * @throws RateLimitReachedException
     * @throws InvalidSlugException
     * @throws InvalidDisplayNameException
     * @throws SlugTakenException
     */
    public function create(User $owner, string $slug, string $displayName, ?string $ip): Organization
    {
        // 2FA is required to create an organization / become an owner.
        if (!$owner->isTotpAuthenticationEnabled()) {
            throw new TwoFactorRequiredException('You must enable two-factor authentication before creating an organization.');
        }

        $this->rateLimiter->assertWithinLimit($owner);

        $slug = Slug::fromUserInput($slug);

        $this->slugChecker->assertClaimable($slug, $owner);

        $organization = Organization::create(new Ulid(), $slug, $displayName);

        try {
            $this->eventStore->append($organization, Actor::owner($owner), $ip);
        } catch (UniqueConstraintViolationException $e) {
            throw new SlugTakenException(sprintf('The organization slug "%s" is already taken.', $slug->value), 0, $e);
        }

        return $organization;
    }
}
