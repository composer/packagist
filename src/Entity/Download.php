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

#[ORM\Entity(repositoryClass: 'App\Entity\DownloadRepository')]
#[ORM\Table(name: 'download')]
#[ORM\Index(name: 'last_updated_idx', columns: ['lastUpdated'])]
#[ORM\Index(name: 'total_idx', columns: ['total'])]
#[ORM\Index(name: 'package_idx', columns: ['package_id'])]
class Download
{
    public const TYPE_PACKAGE = 1;
    public const TYPE_VERSION = 2;

    #[ORM\Id]
    #[ORM\Column(type: 'bigint')]
    public int $id;

    /**
     * @var int one of self::TYPE_*
     */
    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    public int $type;

    /**
     * @var array<int|numeric-string, int> Data is keyed by date in form of YYYYMMDD and as such the keys are technically seen as ints by PHP
     */
    #[ORM\Column(type: 'json')]
    public array $data = [];

    #[ORM\Column(type: 'integer')]
    public int $total = 0;

    #[ORM\Column(type: 'datetime_immutable')]
    public \DateTimeImmutable $lastUpdated;

    #[ORM\ManyToOne(targetEntity: 'App\Entity\Package', inversedBy: 'downloads')]
    public ?Package $package = null;

    public function computeSum(): void
    {
        $this->total = array_sum($this->data);
    }

    public function setId(int $id): void
    {
        $this->id = $id;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function setType(int $type): void
    {
        $this->type = $type;
    }

    public function getType(): int
    {
        return $this->type;
    }

    /**
     * @param array<int|numeric-string, int> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    /**
     * @param numeric-string $key
     */
    public function setDataPoint(string $key, int $value): void
    {
        $this->data[$key] = $value;
    }

    /**
     * @return array<int|numeric-string, int> Key is "YYYYMMDD" which means it always gets converted to an int by php
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function setLastUpdated(\DateTimeImmutable $lastUpdated): void
    {
        $this->lastUpdated = $lastUpdated;
    }

    public function getLastUpdated(): \DateTimeImmutable
    {
        return $this->lastUpdated;
    }

    public function setPackage(Package $package): void
    {
        $this->package = $package;
    }

    public function getPackage(): ?Package
    {
        return $this->package;
    }
}
