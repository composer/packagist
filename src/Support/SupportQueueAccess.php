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

namespace App\Support;

use Symfony\Bundle\SecurityBundle\Security;

/**
 * Which support request types the current admin may see and action.
 *
 * There is no dedicated support role: each type names the role that can carry its action out, and
 * that same role gates reading it. Somebody who cannot disable 2FA has no business reading an
 * account recovery request either. Shared by the admin controller and the admin menu so the queue
 * and the navigation can never disagree.
 */
class SupportQueueAccess
{
    public function __construct(private readonly Security $security)
    {
    }

    /**
     * @return list<SupportRequestType>
     */
    public function visibleTypes(): array
    {
        return array_values(array_filter(
            SupportRequestType::cases(),
            fn (SupportRequestType $type): bool => $this->security->isGranted($type->requiredRole()),
        ));
    }

    public function canSee(SupportRequestType $type): bool
    {
        return $this->security->isGranted($type->requiredRole());
    }

    public function hasAnyAccess(): bool
    {
        return $this->visibleTypes() !== [];
    }
}
