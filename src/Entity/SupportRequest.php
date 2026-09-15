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

use App\Service\IdGenerator;
use App\Support\SupportRequestStatus;
use App\Support\SupportRequestType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * A support task raised from one of the /contact workflows, plus the lost-2FA request raised from
 * the two-factor login prompt.
 *
 * One table with a type discriminator: the four types share their whole lifecycle, and the
 * type-specific payload is three nullable columns. Request bodies, admin notes and replies stay
 * here and never reach audit_log, which every logged-in user can read via /transparency-log.
 */
#[ORM\Entity(repositoryClass: SupportRequestRepository::class)]
#[ORM\Table(name: 'support_request')]
#[ORM\UniqueConstraint(name: 'support_request_public_id_uniq', columns: ['publicId'])]
#[ORM\UniqueConstraint(name: 'support_request_open_uniq', columns: ['userId', 'type', 'openMarker'])]
#[ORM\Index(name: 'support_request_queue_idx', columns: ['status', 'createdAt'])]
#[ORM\Index(name: 'support_request_type_idx', columns: ['type'])]
class SupportRequest
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid')]
    public readonly Ulid $id;

    #[ORM\Column(length: 20)]
    public readonly string $publicId;

    #[ORM\Column(length: 32)]
    public readonly SupportRequestType $type;

    #[ORM\Column(length: 16)]
    public SupportRequestStatus $status;

    #[ORM\ManyToOne(targetEntity: User::class)]
    #[ORM\JoinColumn(name: 'userId', referencedColumnName: 'id', nullable: false, onDelete: 'CASCADE')]
    public readonly User $user;

    #[ORM\Column(type: 'text', nullable: true)]
    public readonly ?string $description;

    /** Vendor claims only. */
    #[ORM\Column(length: 191, nullable: true)]
    public readonly ?string $vendorName;

    /** Package transfers only, one package name per line as the requester typed them. */
    #[ORM\Column(type: 'text', nullable: true)]
    public readonly ?string $packageNames;

    /**
     * SHA-256 of the single-use token in the "this wasn't me" link of the lost-2FA alert mail. Only
     * the hash is stored; the raw token exists solely in that emailed link.
     */
    #[ORM\Column(length: 64, nullable: true)]
    public readonly ?string $cancelTokenHash;

    /**
     * Earliest time a lost-2FA request may be granted. Set for high-value accounts so the owner
     * alert has time to land and be acted on; null means immediately actionable. Stored rather than
     * recomputed so a later download spike cannot restart the clock.
     */
    #[ORM\Column(nullable: true)]
    public readonly ?\DateTimeImmutable $approvableAt;

    /** Requester IP, kept for lost-2FA requests only, where it is a fraud signal. */
    #[ORM\Column(nullable: true, type: 'ipaddress')]
    public ?string $ip = null;

    #[ORM\Column]
    public readonly \DateTimeImmutable $createdAt;

    #[ORM\Column]
    public \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    public ?\DateTimeImmutable $resolvedAt = null;

    /**
     * NULL unless the request is open, which lets support_request_open_uniq cap a user at one open
     * request per type: MySQL unique indexes ignore rows with a NULL component, so resolved and
     * closed rows never collide. Makes the dedupe a database invariant rather than a check that a
     * double submit can race past.
     */
    #[ORM\Column(insertable: false, updatable: false, nullable: true, columnDefinition: "TINYINT(1) GENERATED ALWAYS AS (IF(status = 'open', 1, NULL)) STORED")]
    public ?bool $openMarker = null;

    /** @var Collection<int, SupportRequestMessage> */
    #[ORM\OneToMany(targetEntity: SupportRequestMessage::class, mappedBy: 'request', cascade: ['persist'])]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    public Collection $messages;

    private function __construct(
        SupportRequestType $type,
        User $user,
        ?string $description,
        ?string $vendorName = null,
        ?string $packageNames = null,
        ?string $cancelTokenHash = null,
        ?\DateTimeImmutable $approvableAt = null,
    ) {
        $this->id = new Ulid();
        $this->publicId = IdGenerator::generateSupportRequest();
        $this->type = $type;
        $this->status = SupportRequestStatus::Open;
        $this->user = $user;
        $this->description = $description;
        $this->vendorName = $vendorName;
        $this->packageNames = $packageNames;
        $this->cancelTokenHash = $cancelTokenHash;
        $this->approvableAt = $approvableAt;
        $this->messages = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public static function lostTwoFactor(User $user, ?string $description, string $cancelToken, ?\DateTimeImmutable $approvableAt): self
    {
        return new self(
            SupportRequestType::LostTwoFactor,
            $user,
            $description,
            cancelTokenHash: self::hashCancelToken($cancelToken),
            approvableAt: $approvableAt,
        );
    }

    public static function packageTransfer(User $user, string $packageNames, string $description): self
    {
        return new self(SupportRequestType::PackageTransfer, $user, $description, packageNames: $packageNames);
    }

    public static function vendorClaim(User $user, string $vendorName, string $description): self
    {
        return new self(SupportRequestType::VendorClaim, $user, $description, vendorName: $vendorName);
    }

    public static function accountDeletion(User $user, string $description): self
    {
        return new self(SupportRequestType::AccountDeletion, $user, $description);
    }

    public static function hashCancelToken(string $token): string
    {
        return hash('sha256', $token);
    }

    public function isOpen(): bool
    {
        return $this->status === SupportRequestStatus::Open;
    }

    /** Whether the cooling-off period, if any, has elapsed. Re-checked server-side on every grant. */
    public function isApprovable(\DateTimeImmutable $now): bool
    {
        return $this->approvableAt === null || $this->approvableAt <= $now;
    }

    public function matchesCancelToken(string $token): bool
    {
        return $this->cancelTokenHash !== null && hash_equals($this->cancelTokenHash, self::hashCancelToken($token));
    }

    public function resolve(\DateTimeImmutable $now): void
    {
        $this->status = SupportRequestStatus::Resolved;
        $this->resolvedAt = $now;
        $this->updatedAt = $now;
    }

    public function close(\DateTimeImmutable $now): void
    {
        $this->status = SupportRequestStatus::Closed;
        $this->resolvedAt = $now;
        $this->updatedAt = $now;
    }

    public function reopen(\DateTimeImmutable $now): void
    {
        $this->status = SupportRequestStatus::Open;
        $this->resolvedAt = null;
        $this->updatedAt = $now;
    }

    public function touch(\DateTimeImmutable $now): void
    {
        $this->updatedAt = $now;
    }

    /** One-line description for the admin queue table. */
    public function summary(): string
    {
        $summary = match ($this->type) {
            SupportRequestType::VendorClaim => (string) $this->vendorName,
            SupportRequestType::PackageTransfer => str_replace("\n", ', ', trim((string) $this->packageNames)),
            default => (string) $this->description,
        };

        return mb_strimwidth(trim($summary), 0, 80, '…');
    }

    /** @return list<string> the package names the requester listed, for a transfer request */
    public function packageNameList(): array
    {
        if ($this->packageNames === null) {
            return [];
        }

        return array_values(array_filter(array_map(trim(...), explode("\n", $this->packageNames)), static fn (string $line): bool => $line !== ''));
    }
}
