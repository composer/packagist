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
use App\Form\Model\FilterListEntryRequest;
use App\Service\IdGenerator;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: FilterListEntryRepository::class)]
#[ORM\Table(name: 'filter_list_entry')]
#[ORM\UniqueConstraint(name: 'list_package_version_source_idx', columns: ['list', 'packageName', 'version', 'source'])]
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

    #[ORM\Column(length: 32)]
    private FilterLists $list;

    #[ORM\Column]
    private \DateTimeImmutable $createdAt;
    #[ORM\Column]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column]
    private string $publicId;

    #[ORM\Column(length: 32)]
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

    #[ORM\Column(type: 'text', nullable: true)]
    private ?string $internalNote = null;

    private function __construct(
        FilterLists $list,
        string $packageName,
        string $version,
        ?string $reason,
        ?string $link,
        FilterSources $source,
    ) {
        $this->assignPublicId();
        $this->list = $list;
        $this->packageName = $packageName;
        $this->version = $version;
        $this->reason = $reason;
        $this->link = $link;
        $this->source = $source;
        $this->disabled = false;

        $this->createdAt = $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Creates an entry from an upstream-reported {@see RemoteFilterListEntry}
     * during a provider sync.
     */
    public static function fromRemote(RemoteFilterListEntry $remote): self
    {
        return new self(
            $remote->list,
            $remote->packageName,
            $remote->version,
            $remote->reason,
            $remote->link,
            $remote->source,
        );
    }

    /**
     * Creates an entry that was added manually by an admin. Manual entries have
     * no upstream identity, so {@see FilterSources::PACKAGIST} is recorded as the
     * source and the version is stored directly rather than as an overwrite.
     */
    public static function createManual(FilterListEntryRequest $request): self
    {
        $entry = new self(
            $request->list ?? throw new \InvalidArgumentException('A filter list is required to create a manual entry.'),
            $request->packageName,
            $request->version,
            $request->reason,
            $request->link,
            FilterSources::PACKAGIST,
        );
        $entry->internalNote = $request->internalNote === '' ? null : $request->internalNote;

        return $entry;
    }

    public function updateAttributes(string $version, ?string $internalNote = null): void
    {
        $this->overwriteVersion = $version === $this->version ? null : $version;
        $this->internalNote = $internalNote === '' ? null : $internalNote;
        $this->updatedAt = new \DateTimeImmutable();
    }

    /**
     * Updates the editable attributes of a manually created entry. The package
     * name is intentionally excluded: changing it disables this entry and
     * creates a fresh one instead (see the admin controller).
     */
    public function updateManualEntry(FilterLists $list, string $version, ?string $reason, ?string $link, ?string $internalNote = null): void
    {
        $this->list = $list;
        $this->version = $version;
        $this->reason = $reason;
        $this->link = $link;
        $this->internalNote = $internalNote === '' ? null : $internalNote;
        $this->updatedAt = new \DateTimeImmutable();
    }

    public function isManual(): bool
    {
        return $this->source === FilterSources::PACKAGIST;
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

    public function getInternalNote(): ?string
    {
        return $this->internalNote;
    }

    public function getId(): ?int
    {
        return $this->id ?? null;
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
