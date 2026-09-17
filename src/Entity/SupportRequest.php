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
use App\Support\Attributes\SupportRequestAttributes;
use App\Support\SupportRequestStatus;
use App\Support\SupportRequestType;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * A support task raised from one of the /contact workflows, plus the lost-2FA request raised from
 * the two-factor login prompt.
 *
 * One table with a type discriminator: the four types share their whole lifecycle, and whatever
 * only one of them needs goes in the attributes JSON blob rather than into a nullable column of its
 * own. Request bodies, admin notes and replies stay here and never reach audit_log, which every
 * logged-in user can read via /transparency-log.
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

    /**
     * Raw type-specific payload. Read through {@see $attributes}; this stays private so nothing
     * outside the entity has to know the shape per type.
     *
     * @var array<string, mixed>
     */
    #[ORM\Column(name: 'attributes', type: Types::JSON)]
    private array $attributeData;

    private ?SupportRequestAttributes $hydrated = null;

    /** The payload for this request's type. */
    public SupportRequestAttributes $attributes {
        get => $this->hydrated ??= $this->type->hydrateAttributes($this->attributeData);
    }

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

    /**
     * Read side only: messages are persisted explicitly by whoever creates them, so this collection
     * does not reflect one until the next load.
     *
     * @var Collection<int, SupportRequestMessage>
     */
    #[ORM\OneToMany(targetEntity: SupportRequestMessage::class, mappedBy: 'request')]
    #[ORM\OrderBy(['createdAt' => 'ASC'])]
    public Collection $messages;

    private function __construct(
        User $user,
        ?string $description,
        SupportRequestAttributes $attributes,
        ?string $ip,
    ) {
        $this->id = new Ulid();
        $this->publicId = IdGenerator::generateSupportRequest();
        // Taken from the payload rather than passed alongside it, so the two can never disagree.
        $this->type = $attributes->type();
        $this->status = SupportRequestStatus::Open;
        $this->user = $user;
        $this->description = $description;
        $this->attributeData = $attributes->toArray();
        $this->hydrated = $attributes;
        $this->ip = $ip;
        $this->messages = new ArrayCollection();
        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public static function create(User $user, ?string $description, SupportRequestAttributes $attributes, ?string $ip = null): self
    {
        return new self($user, $description, $attributes, $ip);
    }

    /**
     * The attributes narrowed to the class this request's type uses, for callers that have already
     * established the type.
     *
     * @template T of SupportRequestAttributes
     *
     * @param class-string<T> $class
     *
     * @return T
     */
    public function attributesOf(string $class): SupportRequestAttributes
    {
        $attributes = $this->attributes;
        if (!$attributes instanceof $class) {
            throw new \LogicException('Request '.$this->publicId.' is a '.$this->type->value.', which carries '.$attributes::class.', not '.$class);
        }

        return $attributes;
    }

    public function isOpen(): bool
    {
        return $this->status === SupportRequestStatus::Open;
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
        return mb_strimwidth(trim($this->attributes->summary() ?? (string) $this->description), 0, 80, '…');
    }
}
