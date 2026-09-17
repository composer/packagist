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

use App\Entity\SupportRequest;
use App\Support\Attributes\AccountDeletionAttributes;
use App\Support\Attributes\LostTwoFactorAttributes;
use App\Support\Attributes\PackageTransferAttributes;
use App\Support\Attributes\SupportRequestAttributes;
use App\Support\Attributes\VendorClaimAttributes;

enum SupportRequestType: string
{
    case LostTwoFactor = 'lost_2fa';
    case PackageTransfer = 'package_transfer';
    case VendorClaim = 'vendor_claim';
    case AccountDeletion = 'account_deletion';

    public function label(): string
    {
        return match ($this) {
            self::LostTwoFactor => 'Lost two-factor access',
            self::PackageTransfer => 'Package transfer',
            self::VendorClaim => 'Vendor name claim',
            self::AccountDeletion => 'Account deletion',
        };
    }

    /**
     * The role that may both see and action requests of this type. Single source of truth for the
     * admin queue: a moderator who cannot carry out the action has no business reading the request
     * either, so visibility and capability are deliberately the same check.
     */
    public function requiredRole(): string
    {
        return match ($this) {
            self::LostTwoFactor => 'ROLE_DISABLE_2FA',
            self::PackageTransfer, self::VendorClaim => 'ROLE_EDIT_PACKAGES',
            // Matches the gate in UserController::deleteUserAction()
            self::AccountDeletion => 'ROLE_ADMIN',
        };
    }

    /**
     * Maps the stored attributes blob onto the value object for this type. The one place that knows
     * which payload shape belongs to which request type.
     *
     * @param array<string, mixed> $data
     */
    public function hydrateAttributes(array $data): SupportRequestAttributes
    {
        return match ($this) {
            self::LostTwoFactor => LostTwoFactorAttributes::fromArray($data),
            self::PackageTransfer => PackageTransferAttributes::fromArray($data),
            self::VendorClaim => VendorClaimAttributes::fromArray($data),
            self::AccountDeletion => AccountDeletionAttributes::fromArray($data),
        };
    }

    /**
     * Sent verbatim when a lost-2FA request is granted, so unlike {@see suggestedReply()} it carries
     * no draft branches for an admin to delete: nobody edits this before it goes out.
     */
    public static function twoFactorGrantedReply(SupportRequest $request): string
    {
        $username = $request->user->getUsername();

        return <<<TXT
            Hi {$username},

            Your request to reset two-factor authentication has been approved, and two-factor
            authentication is now switched off on your account. You can sign in with your password
            alone.

            Please set it up again as soon as you have access, from your account settings. If you did
            not make this request, reply to this email straight away.
            TXT;
    }

    /**
     * Starting point for the admin's reply, not a canned response. Each type offers the two likely
     * branches so the admin deletes one and edits the rest.
     *
     * Only ever used to prefill the reply textarea. The one message the system sends on its own is
     * {@see twoFactorGrantedReply()}.
     */
    public function suggestedReply(SupportRequest $request): string
    {
        $username = $request->user->getUsername();
        $attributes = $request->attributes;
        // Pulled out ahead of the match so each arm stays a plain heredoc.
        $packageNames = $attributes instanceof PackageTransferAttributes ? implode("\n", $attributes->packageNames) : '';
        $vendorName = $attributes instanceof VendorClaimAttributes ? $attributes->vendorName : '';

        return match ($this) {
            self::LostTwoFactor => <<<TXT
                Hi {$username},

                Before we can reset two-factor authentication on your account, we need to confirm you
                control it. Please push a file named packagist-verify.txt to the default branch of the
                repository behind one of your packages, and reply here once it is there.

                --- or ---

                We are not able to reset two-factor authentication on this account. Nothing has been
                changed. If you still have your backup code, enter it in place of the authentication
                code when logging in.
                TXT,
            self::PackageTransfer => <<<TXT
                Hi {$username},

                Thanks for getting in touch. We have transferred the following package(s) to your
                account:

                {$packageNames}

                They should now show up under "My packages". If anything is missing, just reply here.

                --- or ---

                Before we can transfer these, we need a bit more information: please let us know how
                you are related to the current maintainer(s), and whether you have already tried to
                reach them.
                TXT,
            self::VendorClaim => <<<TXT
                Hi {$username},

                Thanks for getting in touch about the "{$vendorName}" vendor namespace. We have
                given your account access to it, so you can now publish packages under that name.

                --- or ---

                We are not able to hand over "{$vendorName}": the packages under it are in
                active use by another account. If you believe those packages are yours, reply with
                something that shows it, such as commit access to the repositories they point at.
                TXT,
            self::AccountDeletion => <<<TXT
                Hi {$username},

                Thanks for getting in touch. Before we delete your account we want to confirm what
                should happen to the packages you still maintain, since deletion cannot be undone.

                Please confirm the disposition you picked, and we will take care of the rest.
                TXT,
        };
    }
}
