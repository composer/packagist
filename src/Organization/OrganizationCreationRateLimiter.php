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

use App\Entity\OrganizationEventRepository;
use App\Entity\User;
use App\Organization\Domain\Event\OrganizationCreated;
use App\Organization\Domain\Exception\RateLimitReachedException;

/**
 * Limits how many organizations a user may create per rolling window. Counts
 * `organization-created` events for the user in the event stream. Limits are configurable (see services.yaml);
 * the actual values are still TBD.
 */
final class OrganizationCreationRateLimiter
{
    public function __construct(
        private readonly OrganizationEventRepository $events,
        private readonly int $maxPerWindow = 5,
        private readonly int $windowHours = 24,
    ) {
    }

    /**
     * @throws RateLimitReachedException if the user has reached the creation limit for the current window
     */
    public function assertWithinLimit(User $user): void
    {
        $since = new \DateTimeImmutable(sprintf('-%d hours', $this->windowHours));
        $created = $this->events->countByActorSince($user->getId(), OrganizationCreated::TYPE, $since);

        if ($created >= $this->maxPerWindow) {
            throw new RateLimitReachedException(sprintf('You can create at most %d organizations per %d hours. Please try again later.', $this->maxPerWindow, $this->windowHours));
        }
    }
}
