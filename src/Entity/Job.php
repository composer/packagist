<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;

/**
 * @ORM\Entity(repositoryClass="App\Entity\JobRepository")
 * @ORM\Table(
 *     name="job",
 *     indexes={
 *         @ORM\Index(name="type_idx",columns={"type"}),
 *         @ORM\Index(name="status_idx",columns={"status"}),
 *         @ORM\Index(name="execute_dt_idx",columns={"executeAfter"}),
 *         @ORM\Index(name="creation_idx",columns={"createdAt"}),
 *         @ORM\Index(name="completion_idx",columns={"completedAt"}),
 *         @ORM\Index(name="started_idx",columns={"startedAt"}),
 *         @ORM\Index(name="package_id_idx",columns={"packageId"})
 *     }
 * )
 */
class Job
{
    const STATUS_QUEUED = 'queued';
    const STATUS_STARTED = 'started';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PACKAGE_GONE = 'package_gone';
    const STATUS_PACKAGE_DELETED = 'package_deleted';
    const STATUS_FAILED = 'failed'; // failed in an expected/correct way
    const STATUS_ERRORED = 'errored'; // unexpected failure
    const STATUS_TIMEOUT = 'timeout'; // job was marked timed out
    const STATUS_RESCHEDULE = 'reschedule';

    /**
     * @ORM\Id
     * @ORM\Column(type="string")
     */
    private $id;

    /**
     * @ORM\Column(type="string")
     */
    private $type;

    /**
     * @ORM\Column(type="json_array")
     */
    private $payload;

    /**
     * One of queued, started, completed, failed
     *
     * @ORM\Column(type="string")
     */
    private $status = self::STATUS_QUEUED;

    /**
     * @ORM\Column(type="json_array", nullable=true)
     */
    private $result;

    /**
     * @ORM\Column(type="datetime")
     */
    private $createdAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $startedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $completedAt;

    /**
     * @ORM\Column(type="datetime", nullable=true)
     */
    private $executeAfter;

    /**
     * @ORM\Column(type="integer", nullable=true)
     */
    private $packageId;

    public function start()
    {
        $this->startedAt = new \DateTime();
        $this->status = self::STATUS_STARTED;
    }

    public function complete(array $result)
    {
        $this->result = $result;
        $this->completedAt = new \DateTime();
        $this->status = $result['status'];
    }

    public function reschedule(\DateTimeInterface $when)
    {
        $this->status = self::STATUS_QUEUED;
        $this->startedAt = null;
        $this->setExecuteAfter($when);
    }

    public function setId(string $id)
    {
        $this->id = $id;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function setPackageId(int $packageId)
    {
        $this->packageId = $packageId;
    }

    public function getPackageId()
    {
        return $this->packageId;
    }

    public function setType(string $type)
    {
        $this->type = $type;
    }

    public function getType(): string
    {
        return $this->type;
    }

    public function setPayload(array $payload)
    {
        $this->payload = $payload;
    }

    public function getPayload(): array
    {
        return $this->payload;
    }

    public function setStatus(string $status)
    {
        $this->status = $status;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getResult()
    {
        return $this->result;
    }

    public function setCreatedAt(DateTimeInterface $createdAt)
    {
        $this->createdAt = $createdAt;
    }

    public function getCreatedAt(): DateTimeInterface
    {
        return $this->createdAt;
    }

    public function getStartedAt(): DateTimeInterface
    {
        return $this->startedAt;
    }

    public function setExecuteAfter(DateTimeInterface $executeAfter)
    {
        $this->executeAfter = $executeAfter;
    }

    public function getExecuteAfter()
    {
        return $this->executeAfter;
    }

    public function getCompletedAt()
    {
        return $this->completedAt;
    }
}
