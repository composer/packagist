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

namespace App\Tests\Security\WebAuthn;

use App\Security\WebAuthn\AttestationPolicy;
use App\Security\WebAuthn\AuthenticatorRequirement;
use App\Security\WebAuthn\CredentialRequirement;
use App\Security\WebAuthn\CredentialType;
use App\Security\WebAuthn\FidoMetadataRepository;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\NilUuid;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\MetadataService\Statement\MetadataStatement;
use Webauthn\MetadataService\Statement\StatusReport;
use Webauthn\TrustPath\CertificateTrustPath;

class AttestationPolicyTest extends TestCase
{
    #[TestWith([['hardware', 'secure_element'], AuthenticatorRequirement::HardwareProtected])]
    #[TestWith([['secure_element'], AuthenticatorRequirement::HardwareProtected])]
    #[TestWith([['tee'], AuthenticatorRequirement::HardwareProtected])]
    #[TestWith([['hardware', 'remote_handle'], AuthenticatorRequirement::HardwareProtected])]
    #[TestWith([['hardware', 'secure_element'], AuthenticatorRequirement::SecureElementProtected])]
    #[TestWith([['hardware', 'tee'], AuthenticatorRequirement::TrustedExecutionEnvironment])]
    public function testRequirementsAreEvaluatedAgainstTrustedMetadata(array $protection, AuthenticatorRequirement $requirement): void
    {
        self::assertSame('Test hardware key', $this->policy($protection)->verify($this->record(), [], [$requirement]));
    }

    #[TestWith([['software'], AuthenticatorRequirement::HardwareProtected])]
    #[TestWith([['hardware', 'software'], AuthenticatorRequirement::HardwareProtected])]
    #[TestWith([['tee', 'secure_element'], AuthenticatorRequirement::HardwareProtected])]
    #[TestWith([['hardware'], AuthenticatorRequirement::SecureElementProtected])]
    #[TestWith([['hardware', 'secure_element'], AuthenticatorRequirement::TrustedExecutionEnvironment])]
    #[TestWith([['hardware', 'tee'], AuthenticatorRequirement::SecureElementProtected])]
    public function testMissingRequirementsAreRejected(array $protection, AuthenticatorRequirement $requirement): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->policy($protection)->verify($this->record(), [], [$requirement]);
    }

    public function testRequirementsCanBeCombined(): void
    {
        self::assertSame('Test hardware key', $this->policy(['hardware', 'secure_element'])->verify(
            $this->record(), [], [AuthenticatorRequirement::HardwareProtected, AuthenticatorRequirement::SecureElementProtected],
        ));
    }

    public function testCredentialAndAuthenticatorRequirementsCanBeCombined(): void
    {
        self::assertSame('Test hardware key', $this->policy(['hardware'])->verify(
            $this->record(), [CredentialRequirement::DeviceBound], [AuthenticatorRequirement::HardwareProtected],
        ));
    }

    #[TestWith([true, false])]
    #[TestWith([false, true])]
    #[TestWith([null, null])]
    public function testDeviceBoundRequirementRejectsBackupEligibleOrUnknownCredentials(?bool $eligible, ?bool $backedUp): void
    {
        $record = $this->record();
        $record->backupEligible = $eligible;
        $record->backupStatus = $backedUp;
        $this->expectException(\UnexpectedValueException::class);
        $this->policy(['hardware', 'secure_element'])->verify($record, [CredentialRequirement::DeviceBound]);
    }

    #[TestWith([true, false])]
    #[TestWith([true, true])]
    public function testHardwareProtectionDoesNotImplicitlyRequireDeviceBinding(bool $eligible, bool $backedUp): void
    {
        $record = $this->record();
        $record->backupEligible = $eligible;
        $record->backupStatus = $backedUp;
        self::assertSame('Test hardware key', $this->policy(['hardware'])->verify($record, [], [AuthenticatorRequirement::HardwareProtected]));
    }

    public function testDeviceBindingDoesNotImplicitlyRequireHardwareProtection(): void
    {
        self::assertSame('Test hardware key', $this->policy(['software'])->verify($this->record(), [CredentialRequirement::DeviceBound]));
    }

    public function testAttestationAloneDoesNotRequireHardwareOrDeviceBinding(): void
    {
        $record = $this->record();
        $record->backupEligible = true;
        $record->backupStatus = true;
        self::assertSame('Test hardware key', $this->policy(['software'])->verify($record));
    }

    public function testHardwareAndDeviceBoundRequirementsAreBothEnforced(): void
    {
        $record = $this->record();
        $record->backupEligible = true;
        $record->backupStatus = true;
        $this->expectException(\UnexpectedValueException::class);
        $credentialType = CredentialType::VerifiedHardware;
        $this->policy(['hardware'])->verify($record, $credentialType->credentialRequirements(), $credentialType->authenticatorRequirements());
    }

    public function testNilAuthenticatorIdentifiersAreRejected(): void
    {
        $record = $this->record();
        $record->aaguid = Uuid::fromBinary(str_repeat("\0", 16));
        self::assertInstanceOf(NilUuid::class, $record->aaguid);

        $this->expectException(\UnexpectedValueException::class);
        $this->policy(['hardware'])->verify($record, [], [AuthenticatorRequirement::HardwareProtected]);
    }

    public function testMissingStatusReportsAreRejected(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('The authenticator has no trusted status report');
        $this->policy(['hardware'], [])->verify($this->record());
    }

    #[TestWith(['REVOKED'])]
    #[TestWith(['USER_KEY_REMOTE_COMPROMISE'])]
    #[TestWith(['USER_KEY_PHYSICAL_COMPROMISE'])]
    #[TestWith(['ATTESTATION_KEY_COMPROMISE'])]
    #[TestWith(['USER_VERIFICATION_BYPASS'])]
    public function testUnsafeReportsAreRejectedEvenAlongsideCertification(string $status): void
    {
        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage($status);
        $this->policy(['hardware'], ['FIDO_CERTIFIED', $status, 'FIDO_CERTIFIED_L1'])->verify($this->record());
    }

    #[TestWith(['none'])]
    #[TestWith(['self'])]
    public function testUntrustedAttestationTypesAreRejected(string $attestationType): void
    {
        $record = $this->record();
        $record->attestationType = $attestationType;

        $this->expectException(\UnexpectedValueException::class);
        $this->expectExceptionMessage('Attested enrollment requires identifiable certificate-backed attestation');
        $this->policy(['hardware'])->verify($record);
    }

    private function policy(array $protection, array $statuses = ['FIDO_CERTIFIED']): AttestationPolicy
    {
        $statement = new MetadataStatement('Test hardware key', 1, 'fido2', 3, [], [], [], [], [], [], [], []);
        $statement->keyProtection = $protection;
        $repository = $this->createStub(FidoMetadataRepository::class);
        $repository->method('findOneByAAGUID')->willReturn($statement);
        $repository->method('findStatusReportsByAAGUID')->willReturn(array_map(static fn (string $status) => new StatusReport($status, null, null, null, null, null, null, null), $statuses));

        return new AttestationPolicy($repository);
    }

    private function record(): CredentialRecord
    {
        return new CredentialRecord('credential', 'public-key', [], 'basic', new CertificateTrustPath(['certificate']), Uuid::fromString(AttestationAuthority::AAGUID), 'public-key', 'user', 0, null, false, false);
    }
}
