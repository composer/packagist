<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;

/**
 * @ORM\Entity(repositoryClass="App\Entity\DownloadRepository")
 * @ORM\Table(
 *     name="download",
 *     indexes={
 *         @ORM\Index(name="last_updated_idx",columns={"lastUpdated"}),
 *         @ORM\Index(name="total_idx",columns={"total"}),
 *         @ORM\Index(name="package_idx",columns={"package_id"})
 *     }
 * )
 */
class Download
{
    const TYPE_PACKAGE = 1;
    const TYPE_VERSION = 2;

    /**
     * @ORM\Id
     * @ORM\Column(type="bigint")
     */
    public $id;

    /**
     * @ORM\Id
     * @ORM\Column(type="smallint")
     */
    public $type;

    /**
     * @ORM\Column(type="json_array")
     */
    public $data;

    /**
     * @ORM\Column(type="integer")
     */
    public $total;

    /**
     * @ORM\Column(type="datetime")
     */
    public $lastUpdated;

    /**
     * @ORM\ManyToOne(targetEntity="App\Entity\Package", inversedBy="downloads")
     */
    public $package;

    public function __construct()
    {
        $this->data = [];
        $this->total = 0;
    }

    public function computeSum()
    {
        $this->total = array_sum($this->data);
    }

    public function setId(int $id)
    {
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setType(int $type)
    {
        $this->type = $type;
    }

    public function getType(): int
    {
        return $this->type;
    }

    public function setData(array $data)
    {
        $this->data = $data;
    }

    public function setDataPoint($key, $value)
    {
        $this->data[$key] = $value;
    }

    public function getData(): array
    {
        return $this->data;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setLastUpdated(DateTimeInterface $lastUpdated)
    {
        $this->lastUpdated = $lastUpdated;
    }

    public function getLastUpdated(): DateTimeInterface
    {
        return $this->lastUpdated;
    }

    public function setPackage(Package $package)
    {
        $this->package = $package;
    }

    public function getPackage(): Package
    {
        return $this->package;
    }
}
