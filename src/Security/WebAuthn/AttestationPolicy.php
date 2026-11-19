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

use Symfony\Component\Uid\NilUuid;
use Webauthn\AttestationStatement\AttestationStatement;
use Webauthn\CredentialRecord;

class AttestationPolicy
{
    // Accept certificate-backed attestation types that can be validated against trusted FIDO metadata
    private const array SUPPORTED_ATTESTATION_TYPES = [
        AttestationStatement::TYPE_BASIC,
        AttestationStatement::TYPE_ATTCA,
        AttestationStatement::TYPE_ANONCA,
    ];

    public function __construct(private FidoMetadataRepository $metadata)
    {
    }

    /**
     * @param list<CredentialRequirement>    $credentialRequirements
     * @param list<AuthenticatorRequirement> $authenticatorRequirements
     */
    public function verify(CredentialRecord $record, array $credentialRequirements = [], array $authenticatorRequirements = []): string
    {
        if (!$this->hasCertificateBackedAttestation($record)
            || !$this->hasAuthenticatorIdentifier($record)) {
            throw new \UnexpectedValueException('Attested enrollment requires identifiable certificate-backed attestation');
        }

        $aaguid = $record->aaguid->toRfc4122();
        $statement = $this->metadata->findOneByAAGUID($aaguid);

        if ($statement === null) {
            throw new \UnexpectedValueException('The authenticator has no trusted metadata');
        }

        $this->verifyStatus($aaguid);

        foreach ($credentialRequirements as $requirement) {
            if (!$requirement->isSatisfiedBy($record)) {
                throw new \UnexpectedValueException('The authenticator does not satisfy '.$requirement->name);
            }
        }

        foreach ($authenticatorRequirements as $requirement) {
            if (!$requirement->isSatisfiedBy($statement)) {
                throw new \UnexpectedValueException('The authenticator does not satisfy '.$requirement->name);
            }
        }

        return $statement->description;
    }

    private function hasCertificateBackedAttestation(CredentialRecord $record): bool
    {
        return \in_array($record->attestationType, self::SUPPORTED_ATTESTATION_TYPES, true);
    }

    private function hasAuthenticatorIdentifier(CredentialRecord $record): bool
    {
        return !$record->aaguid instanceof NilUuid;
    }

    private function verifyStatus(string $aaguid): void
    {
        // The bundle rejects compromised or revoked reports, but allows an empty list
        $reports = $this->metadata->findStatusReportsByAAGUID($aaguid);

        if ($reports === []) {
            throw new \UnexpectedValueException('The authenticator has no trusted status report');
        }
    }
}
