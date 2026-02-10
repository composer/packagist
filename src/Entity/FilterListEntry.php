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

use App\FilterList\FilterListCategories;
use App\FilterList\FilterLists;
use App\FilterList\RemoteFilterListEntry;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FilterListEntryRepository::class)]
#[ORM\Table(name: 'filter_list_entry')]
#[ORM\UniqueConstraint(name: 'list_package_version_idx', columns: ['list', 'packageName', 'version'])]
#[ORM\Index(name: 'category_list_idx', columns: ['category', 'list'])]
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

    #[ORM\Column(nullable: true)]
    private string|null $link = null;

    #[ORM\Column]
    private FilterLists $list;

    #[ORM\Column]
    private FilterListCategories $category;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    public function __construct(RemoteFilterListEntry $remote)
    {
        $this->packageName = $remote->packageName;
        $this->version = $remote->version;
        $this->link = $remote->link;
        $this->list = $remote->list;
        $this->category = $remote->category;

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

    public function getCategory(): FilterListCategories
    {
        return $this->category;
    }
}
