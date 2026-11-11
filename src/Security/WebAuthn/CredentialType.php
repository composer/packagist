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

namespace App\Security\WebAuthn;

enum CredentialType: string
{
    case Regular = 'regular';
    case VerifiedHardware = 'hardware';

    /** @return list<CredentialRequirement> */
    public function credentialRequirements(): array
    {
        return match ($this) {
            self::Regular => [],
            self::VerifiedHardware => [CredentialRequirement::DeviceBound],
        };
    }

    /** @return list<AuthenticatorRequirement> */
    public function authenticatorRequirements(): array
    {
        return match ($this) {
            self::Regular => [],
            self::VerifiedHardware => [AuthenticatorRequirement::HardwareProtected],
        };
    }

    public function requiresAttestation(): bool
    {
        return $this !== self::Regular;
    }

    public function creationProfile(): string
    {
        return $this->requiresAttestation() ? 'with_attestation' : 'without_attestation';
    }

    public function label(): string
    {
        return match ($this) {
            self::Regular => 'Passkey or security key',
            self::VerifiedHardware => 'Verified hardware security key',
        };
    }

    public function description(): string
    {
        return match ($this) {
            self::Regular => 'Use your phone, password manager, or security key.',
            self::VerifiedHardware => "Requires a compatible key that provides manufacturer attestation verified against FIDO metadata. Synced passkeys are not supported. Your browser may ask permission to identify the key's model.",
        };
    }
}
