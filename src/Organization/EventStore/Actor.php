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

namespace App\Organization\EventStore;

use App\Entity\User;

/**
 * The actor behind an event. `userId` is the stable user reference; `label` records
 * who triggered the action and `roleInOrg` the org role that authorized it.
 */
final readonly class Actor
{
    public function __construct(
        public ?int $userId,
        public ActorLabel $label,
        public ?OrgRole $roleInOrg = null,
    ) {
    }

    /**
     * The org owner. Until the membership management is done this is exactly the creating user.
     */
    public static function owner(User $user): self
    {
        return new self($user->getId(), ActorLabel::User, OrgRole::Owner);
    }

    public static function packagistAdmin(User $admin): self
    {
        return new self($admin->getId(), ActorLabel::PackagistAdmin, null);
    }
}
