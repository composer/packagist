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

use Webauthn\MetadataService\Statement\MetadataStatement;

enum AuthenticatorRequirement: string
{
    case External = MetadataStatement::ATTACHMENT_HINT_EXTERNAL;
    case HardwareProtected = MetadataStatement::KEY_PROTECTION_HARDWARE;
    case SecureElementProtected = MetadataStatement::KEY_PROTECTION_SECURE_ELEMENT;
    case TrustedExecutionEnvironment = MetadataStatement::KEY_PROTECTION_TEE;

    public function isSatisfiedBy(MetadataStatement $statement): bool
    {
        if ($this === self::External) {
            return \in_array(MetadataStatement::ATTACHMENT_HINT_EXTERNAL, $statement->attachmentHint, true)
                && !\in_array(MetadataStatement::ATTACHMENT_HINT_INTERNAL, $statement->attachmentHint, true);
        }

        if ($this->hasIncompatibleKeyProtection($statement)) {
            return false;
        }

        return match ($this) {
            // A TEE or secure element provides hardware protection even without the broader hardware flag
            self::HardwareProtected => \in_array(MetadataStatement::KEY_PROTECTION_HARDWARE, $statement->keyProtection, true)
                || \in_array(MetadataStatement::KEY_PROTECTION_SECURE_ELEMENT, $statement->keyProtection, true)
                || \in_array(MetadataStatement::KEY_PROTECTION_TEE, $statement->keyProtection, true),
            self::SecureElementProtected, self::TrustedExecutionEnvironment => \in_array($this->value, $statement->keyProtection, true),
        };
    }

    private function hasIncompatibleKeyProtection(MetadataStatement $statement): bool
    {
        return \in_array(MetadataStatement::KEY_PROTECTION_SOFTWARE, $statement->keyProtection, true)
            || (\in_array(MetadataStatement::KEY_PROTECTION_TEE, $statement->keyProtection, true)
                && \in_array(MetadataStatement::KEY_PROTECTION_SECURE_ELEMENT, $statement->keyProtection, true));
    }
}
