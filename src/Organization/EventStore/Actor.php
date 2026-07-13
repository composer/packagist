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
 * who or what triggered the action.
 */
final readonly class Actor
{
    public function __construct(
        public ?int $userId,
        public ActorLabel $label,
    ) {
    }

    /**
     * An org member who is not an owner (e.g. a member leaving on their own). Carries no org role.
     */
    public static function member(User $user): self
    {
        return new self($user->getId(), ActorLabel::User);
    }

    public static function packagistAdmin(User $admin): self
    {
        return new self($admin->getId(), ActorLabel::PackagistAdmin);
    }
}
