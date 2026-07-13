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

namespace App\Security\Voter;

/**
 * Why {@see OrganizationVoter} denied an action. Attached to the vote's extra data under
 * {@see self::VOTE_KEY} so downstream code (e.g. the access-denied listener) can react to the
 * specific cause without re-deriving it, and surfaced in the vote reason for the 403 message.
 */
enum OrganizationAccessDeniedReason
{
    /** The user is not a member of the organization. */
    case NotAMember;

    /** The user is a member but not an owner, and the action is owner-only. */
    case NotAnOwner;

    /** The user is an owner of a live org but has not enabled the required 2FA. */
    case TwoFactorRequired;

    /** The action is reserved for Packagist administrators (e.g. restoring a hidden org). */
    case AdminOnly;

    /** Extra-data key under which the reason is stored on a denied vote. */
    public const string VOTE_KEY = 'organizationAccessDeniedReason';

    public function message(): string
    {
        return match ($this) {
            self::NotAMember => 'You are not a member of this organization.',
            self::NotAnOwner => 'Only organization owners can perform this action.',
            self::TwoFactorRequired => 'Two-factor authentication is required to manage an organization.',
            self::AdminOnly => 'This action is restricted to Packagist administrators.',
        };
    }
}
