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

use App\FilterList\FilterLists;
use App\FilterList\RemoteFilterListEntry;
use App\Service\IdGenerator;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FilterListEntryRepository::class)]
#[ORM\Table(name: 'filter_list_entry')]
#[ORM\UniqueConstraint(name: 'list_package_version_idx', columns: ['list', 'packageName', 'version'])]
#[ORM\Index(name: 'updated_at_idx', columns: ['updatedAt'])]
class FilterListEntry
{
    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[ORM\Column]
    private string $packageName;

    #[ORM\Column]
    private string $version;

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $reason;

    #[ORM\Column(nullable: true)]
    private ?string $link;

    #[ORM\Column]
    private FilterLists $list;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(nullable: true)]
    private ?string $publicId;

    public function __construct(RemoteFilterListEntry $remote)
    {
        $this->assignPublicId();
        $this->packageName = $remote->packageName;
        $this->version = $remote->version;
        $this->link = $remote->link;
        $this->list = $remote->list;
        $this->reason = $remote->reason;

        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getVersion(): string
    {
        return $this->version;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getList(): FilterLists
    {
        return $this->list;
    }

    public function getReason(): ?string
    {
        return $this->reason;
    }

    public function getPublicId(): ?string
    {
        return $this->publicId;
    }

    public function assignPublicId(): void
    {
        $this->publicId = IdGenerator::generateFilterListEntry();
    }
}
