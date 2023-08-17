<?php

declare(strict_types=1);

namespace App\Entity;

use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\GeneratedValue;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Symfony\Component\Uid\AbstractUid;
use Symfony\Component\Uid\Ulid;
use Webauthn\PublicKeyCredentialSource as BasePublicKeyCredentialSource;
use Webauthn\TrustPath\TrustPath;

#[Table(name: "webauthn_credentials")]
#[Entity(repositoryClass: WebauthnCredentialRepository::class)]
class WebauthnCredential extends BasePublicKeyCredentialSource
{
    #[Id]
    #[Column(type: Types::STRING)]
    #[GeneratedValue(strategy: "NONE")]
    private string $id;

    #[Column(type: Types::STRING, nullable: true)]
    private null|string $name;

    #[Column(type: Types::DATETIME_IMMUTABLE)]
    private \DateTimeImmutable $createdAt;

    public function __construct(
        string $publicKeyCredentialId,
        string $type,
        array $transports,
        string $attestationType,
        TrustPath $trustPath,
        AbstractUid $aaguid,
        string $credentialPublicKey,
        string $userHandle,
        int $counter
    )
    {
        $this->id = Ulid::generate();
        $this->createdAt = new \DateTimeImmutable();
        parent::__construct($publicKeyCredentialId, $type, $transports, $attestationType, $trustPath, $aaguid, $credentialPublicKey, $userHandle, $counter);
    }

    public static function createFromPublicKeyCredentialSource(BasePublicKeyCredentialSource $publicKeyCredentialSource): self
    {
        return new self(
            $publicKeyCredentialSource->getPublicKeyCredentialId(),
            $publicKeyCredentialSource->getType(),
            $publicKeyCredentialSource->getTransports(),
            $publicKeyCredentialSource->getAttestationType(),
            $publicKeyCredentialSource->getTrustPath(),
            $publicKeyCredentialSource->getAaguid(),
            $publicKeyCredentialSource->getCredentialPublicKey(),
            $publicKeyCredentialSource->getUserHandle(),
            $publicKeyCredentialSource->getCounter()
        );
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function getName(): null|string
    {
        return $this->name;
    }

    public function setName(string $name): void
    {
        $this->name = $name;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }
}
