<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: 'App\Entity\JobRepository')]
#[ORM\Table(name: 'job')]
#[ORM\Index(name: 'type_idx', columns: ['type'])]
#[ORM\Index(name: 'status_idx', columns: ['status'])]
#[ORM\Index(name: 'execute_dt_idx', columns: ['executeAfter'])]
#[ORM\Index(name: 'creation_idx', columns: ['createdAt'])]
#[ORM\Index(name: 'completion_idx', columns: ['completedAt'])]
#[ORM\Index(name: 'started_idx', columns: ['startedAt'])]
#[ORM\Index(name: 'package_id_idx', columns: ['packageId'])]
class Job
{
    public const STATUS_QUEUED = 'queued';
    public const STATUS_STARTED = 'started';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_PACKAGE_GONE = 'package_gone';
    public const STATUS_PACKAGE_DELETED = 'package_deleted';
    public const STATUS_FAILED = 'failed'; // failed in an expected/correct way
    public const STATUS_ERRORED = 'errored'; // unexpected failure
    public const STATUS_TIMEOUT = 'timeout'; // job was marked timed out
    public const STATUS_RESCHEDULE = 'reschedule';

    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $id;

    #[ORM\Column(type: 'string')]
    private string $type;

    #[ORM\Column(type: 'json')]
    private array $payload = [];

    /**
     * One of queued, started, completed, failed
     *
     * @var self::STATUS_*
     */
    #[ORM\Column(type: 'string')]
    private string $status = self::STATUS_QUEUED;

    #[ORM\Column(type: 'json', nullable: true)]
    private ?array $result = null;

    #[ORM\Column(type: 'datetime')]
    private DateTimeInterface $createdAt;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private DateTimeInterface|null $startedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private DateTimeInterface|null $completedAt = null;

    #[ORM\Column(type: 'datetime', nullable: true)]
    private DateTimeInterface|null $executeAfter = null;

    #[ORM\Column(type: 'integer', nullable: true)]
    private int|null $packageId = null;

    public function __construct(string $id, string $type, array $payload)
    {
        $this->id = $id;
        $this->type = $type;
        $this->payload = $payload;
        $this->createdAt = new \DateTimeImmutable();
    }

    public function start(): void
    {
        $this->startedAt = new \DateTimeImmutable();
        $this->status = self::STATUS_STARTED;
    }

    public function complete(array $result): void
    {
        $this->result = $result;
        $this->completedAt = new \DateTimeImmutable();
        $this->status = $result['status'];
    }

    public function reschedule(DateTimeInterface $when): void
    {
        $this->status = self::STATUS_QUEUED;
        $this->startedAt = null;
        $this->setExecuteAfter($when);
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setPackageId(int|null $packageId): void
    {
        $this->packageId = $packageId;
    }

    public function getPackageId(): int|null
    {
        return $this->packageId;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setStatus(string $status): void
    {
        $this->status = $status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getResult(): ?array
    {
        return $this->result;
    }

    public function setCreatedAt(DateTimeInterface $createdAt): void
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getStartedAt(): DateTimeInterface|null
    {
        return $this->startedAt;
    }

    public function setExecuteAfter(DateTimeInterface|null $executeAfter): void
    {
        $this->executeAfter = $executeAfter;
    }

    public function getExecuteAfter(): DateTimeInterface|null
    {
        return $this->executeAfter;
    }

    public function getCompletedAt(): DateTimeInterface|null
    {
        return $this->completedAt;
    }
}
