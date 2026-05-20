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
use App\FilterList\FilterSources;
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

    #[ORM\Column]
    private string $publicId;

    #[ORM\Column]
    private FilterSources $source;

    #[ORM\Column(options: ['default' => false])]
    private bool $disabled = false;

    /**
     * Admin-supplied version constraint that overrides the upstream-reported
     * one. NULL when no admin override is in effect. The upstream identity is
     * always stored in {@see $version}, allowing the resolver to recognise
     * the entry across syncs regardless of admin overrides.
     */
    #[ORM\Column(nullable: true)]
    private ?string $overwriteVersion = null;

    public function __construct(RemoteFilterListEntry $remote)
    {
        $this->assignPublicId();
        $this->packageName = $remote->packageName;
        $this->version = $remote->version;
        $this->link = $remote->link;
        $this->list = $remote->list;
        $this->reason = $remote->reason;
        $this->source = $remote->source;
        $this->disabled = false;

        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    public function updateAttributes(string $version): void
    {
        $this->overwriteVersion = $version === $this->version ? null : $version;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function disable(): void
    {
        if ($this->disabled) {
            return;
        }

        $this->disabled = true;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function enable(): void
    {
        if (!$this->disabled) {
            return;
        }

        $this->disabled = false;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }

    public function isOverwritten(): bool
    {
        return $this->overwriteVersion !== null;
    }

    public function getOverwriteVersion(): ?string
    {
        return $this->overwriteVersion;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    /**
     * Returns the version constraint that should be exposed to consumers:
     * the admin-supplied override when present, otherwise the upstream value.
     */
    public function getVersion(): string
    {
        return $this->overwriteVersion ?? $this->version;
    }

    /**
     * Returns the upstream-reported version (the identity the resolver uses
     * when matching against the remote feed). Unaffected by admin overrides.
     */
    public function getRemoteVersion(): string
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

    public function getSource(): FilterSources
    {
        return $this->source;
    }

    public function getCreatedAt(): \DateTimeImmutable
    {
        return $this->createdAt;
    }

    public function getUpdatedAt(): \DateTimeImmutable
    {
        return $this->updatedAt;
    }

    private function assignPublicId(): void
    {
        $this->publicId = IdGenerator::generateFilterListEntry();
    }
}
