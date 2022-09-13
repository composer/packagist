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

use Composer\Pcre\Preg;
use Doctrine\ORM\Mapping as ORM;
use DateTimeInterface;

#[ORM\Entity(repositoryClass: 'App\Entity\PhpStatRepository')]
#[ORM\Table(name: 'php_stat')]
#[ORM\Index(name: 'type_idx', columns: ['type'])]
#[ORM\Index(name: 'depth_idx', columns: ['depth'])]
#[ORM\Index(name: 'version_idx', columns: ['version'])]
#[ORM\Index(name: 'last_updated_idx', columns: ['last_updated'])]
#[ORM\Index(name: 'package_idx', columns: ['package_id'])]
class PhpStat
{
    public const TYPE_PHP = 1;
    public const TYPE_PLATFORM = 2;

    public const DEPTH_PACKAGE = 0;
    public const DEPTH_MAJOR = 1;
    public const DEPTH_MINOR = 2;
    public const DEPTH_EXACT = 3;

    /**
     * Version prefix
     *
     * - "" for the overall package stats
     * - x.y for numeric versions (grouped by minor)
     * - x for numeric versions (grouped by major)
     * - Full version for the rest (dev- & co)
     */
    #[ORM\Id]
    #[ORM\Column(type: 'string', length: 191)]
    public string $version;

    /**
     * @var self::TYPE_*
     */
    #[ORM\Id]
    #[ORM\Column(type: 'smallint')]
    public int $type;

    /**
     * DEPTH_MAJOR for x
     * DEPTH_MINOR for x.y
     * DEPTH_EXACT for the rest
     *
     * @var self::DEPTH_*
     */
    #[ORM\Column(type: 'smallint')]
    public int $depth;

    /**
     * array[php-version][Ymd] = downloads
     *
     * @var array<string, array<string, int>>
     */
    #[ORM\Column(type: 'json')]
    public array $data;

    #[ORM\Column(type: 'datetime', name: 'last_updated')]
    public DateTimeInterface $lastUpdated;

    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: 'App\Entity\Package')]
    #[ORM\JoinColumn(name: 'package_id', nullable: false)]
    public Package $package;

    /**
     * @param self::TYPE_* $type
     */
    public function __construct(Package $package, int $type, string $version)
    {
        $this->package = $package;
        $this->type = $type;
        $this->version = $version;

        if ('' === $version) {
            $this->depth = self::DEPTH_PACKAGE;
        } elseif (Preg::isMatch('{^\d+$}', $version)) {
            $this->depth = self::DEPTH_MAJOR;
        } elseif (Preg::isMatch('{^\d+\.\d+$}', $version)) {
            $this->depth = self::DEPTH_MINOR;
        } else {
            $this->depth = self::DEPTH_EXACT;
        }

        $this->data = [];
        $this->lastUpdated = new \DateTimeImmutable();
    }

    /**
     * @return self::TYPE_*
     */
    public function getType(): int
    {
        return $this->type;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    /**
     * @return self::DEPTH_*
     */
    public function getDepth(): int
    {
        return $this->depth;
    }

    /**
     * @param array<string, array<string, int>> $data
     */
    public function setData(array $data): void
    {
        $this->data = $data;
    }

    public function addDataPoint(string $phpMinor, string $date, int $value): void
    {
        $this->data[$phpMinor][$date] = ($this->data[$phpMinor][$date] ?? 0) + $value;
    }

    public function setDataPoint(string $phpMinor, string $date, int $value): void
    {
        $this->data[$phpMinor][$date] = $value;
    }

    /**
     * @return array<string, array<string, int>>
     */
    public function getData(): array
    {
        return $this->data;
    }

    public function setLastUpdated(DateTimeInterface $lastUpdated): void
    {
        $this->lastUpdated = $lastUpdated;
    }

    public function getLastUpdated(): DateTimeInterface
    {
        return $this->lastUpdated;
    }

    public function getPackage(): Package
    {
        return $this->package;
    }
}
