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

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Uuid;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredentialDescriptor;
use Webauthn\TrustPath\TrustPath;

#[ORM\Entity]
#[ORM\Table(name: 'webauthn_credential')]
#[ORM\Index(name: 'webauthn_user_idx', columns: ['user_id'])]
class WebAuthnCredential
{
    #[ORM\Id]
    #[ORM\Column(length: 64)]
    public readonly string $id;

    #[ORM\ManyToOne(inversedBy: 'webAuthnCredentials')]
    #[ORM\JoinColumn(nullable: false, onDelete: 'CASCADE')]
    public readonly User $user;

    #[ORM\Column(length: 100)]
    private string $name;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $createdAt;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $lastUsedAt = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $revokedAt = null;

    #[ORM\Column(type: 'base64')]
    private string $publicKeyCredentialId;

    #[ORM\Column]
    private string $type;

    /** @var string[] */
    #[ORM\Column(type: 'json')]
    private array $transports;

    #[ORM\Column]
    private string $attestationType;

    #[ORM\Column(type: 'trust_path')]
    private TrustPath $trustPath;

    #[ORM\Column(type: 'aaguid', length: 36)]
    private Uuid $aaguid;

    #[ORM\Column(type: 'base64')]
    private string $credentialPublicKey;

    #[ORM\Column]
    private string $userHandle;

    // WebAuthn signature counters use the full unsigned 32-bit range
    // See https://github.com/web-auth/webauthn-framework/issues/1007
    #[ORM\Column(options: ['unsigned' => true])]
    private int $counter;

    /** @var array<string, mixed>|null */
    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $otherUI;

    #[ORM\Column(nullable: true)]
    private ?bool $backupEligible;

    #[ORM\Column(nullable: true)]
    private ?bool $backupStatus;

    #[ORM\Column(nullable: true)]
    private ?bool $uvInitialized;

    public function __construct(User $user, CredentialRecord $record)
    {
        $this->publicKeyCredentialId = $record->publicKeyCredentialId;
        $this->type = $record->type;
        $this->transports = $record->transports;
        $this->attestationType = $record->attestationType;
        $this->trustPath = $record->trustPath;
        $this->aaguid = $record->aaguid;
        $this->credentialPublicKey = $record->credentialPublicKey;
        $this->userHandle = $record->userHandle;
        $this->otherUI = $record->otherUI;
        $this->updateAuthenticatorState($record);

        $this->user = $user;
        $this->id = hash('sha256', $record->publicKeyCredentialId);
        $this->name = 'Passkey or security key';
        $this->createdAt = new \DateTimeImmutable();
    }

    public function toCredentialRecord(): CredentialRecord
    {
        return CredentialRecord::create(
            $this->publicKeyCredentialId,
            $this->type,
            $this->transports,
            $this->attestationType,
            $this->trustPath,
            $this->aaguid,
            $this->credentialPublicKey,
            $this->userHandle,
            $this->counter,
            $this->otherUI,
            $this->backupEligible,
            $this->backupStatus,
            $this->uvInitialized,
        );
    }

    public function getPublicKeyCredentialDescriptor(): PublicKeyCredentialDescriptor
    {
        return PublicKeyCredentialDescriptor::create($this->type, $this->publicKeyCredentialId, $this->transports);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getLastUsedAt(): ?\DateTimeImmutable
    {
        return $this->lastUsedAt;
    }

    public function getRevokedAt(): ?\DateTimeImmutable
    {
        return $this->revokedAt;
    }

    public function rename(string $name): void
    {
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 100) {
            throw new \InvalidArgumentException('webauthn.error.invalid_name');
        }
        $this->name = $name;
    }

    public function recordAuthentication(CredentialRecord $record): void
    {
        $this->updateAuthenticatorState($record);
        $this->lastUsedAt = new \DateTimeImmutable();
    }

    private function updateAuthenticatorState(CredentialRecord $record): void
    {
        $this->counter = $record->counter;
        $this->backupEligible = $record->backupEligible;
        $this->backupStatus = $record->backupStatus;
        $this->uvInitialized = $record->uvInitialized;
    }

    public function revoke(): void
    {
        $this->revokedAt ??= new \DateTimeImmutable();
    }
}
