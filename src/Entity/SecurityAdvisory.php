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

use App\SecurityAdvisory\AdvisoryParser;
use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\Severity;
use App\Service\IdGenerator;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity(repositoryClass: 'App\Entity\SecurityAdvisoryRepository')]
#[ORM\Table(name: 'security_advisory')]
#[ORM\UniqueConstraint(name: 'source_remoteid_package_idx', columns: ['source', 'remoteId', 'packageName'])]
#[ORM\UniqueConstraint(name: 'package_name_active_cve_idx', columns: ['packageName', 'activeCve'])]
#[ORM\Index(name: 'package_name_idx', columns: ['packageName'])]
#[ORM\Index(name: 'updated_at_idx', columns: ['updatedAt'])]
#[ORM\Index(name: 'withdrawn_at_idx', columns: ['withdrawnAt'])]
class SecurityAdvisory
{
    public const PACKAGIST_ORG = 'https://packagist.org';

    #[ORM\Id]
    #[ORM\Column(type: 'integer')]
    #[ORM\GeneratedValue(strategy: 'AUTO')]
    private int $id;

    #[ORM\Column(type: 'string', unique: true)]
    private string $packagistAdvisoryId;

    #[ORM\Column(type: 'string')]
    private string $remoteId;

    #[ORM\Column(type: 'string')]
    private string $packageName;

    #[ORM\Column(type: 'string')]
    private string $title;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $link = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $cve = null;

    #[ORM\Column(type: 'text')]
    private string $affectedVersions;

    #[ORM\Column(type: 'string')]
    private string $source;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $reportedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private \DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'string', nullable: true)]
    private ?string $composerRepository = null;

    #[ORM\Column(nullable: true)]
    private ?Severity $severity = null;

    #[ORM\Column(type: 'datetime_immutable', nullable: true)]
    private ?\DateTimeImmutable $withdrawnAt = null;

    /**
     * DB-only generated column backing the package_name_active_cve_idx unique constraint; Never read or written from PHP.
     */
    #[ORM\Column(name: 'activeCve', type: 'string', nullable: true, insertable: false, updatable: false, generated: 'ALWAYS', columnDefinition: 'VARCHAR(255) GENERATED ALWAYS AS (IF(withdrawnAt IS NULL, cve, NULL)) VIRTUAL')]
    private ?string $activeCve = null;

    /**
     * @var Collection<int, SecurityAdvisorySource>&Selectable<int, SecurityAdvisorySource>
     */
    #[ORM\OneToMany(targetEntity: SecurityAdvisorySource::class, mappedBy: 'securityAdvisory', cascade: ['persist'])]
    private Collection $sources;

    public function __construct(RemoteSecurityAdvisory $advisory, string $source)
    {
        $this->sources = new ArrayCollection();

        $this->source = $source;
        $this->assignPackagistAdvisoryId();

        $this->updatedAt = new \DateTimeImmutable();

        $this->copyAdvisory($advisory, true);
        $this->addSource($advisory->id, $source, $advisory->severity, $advisory->date);
    }

    public function updateAdvisory(RemoteSecurityAdvisory $advisory): void
    {
        $this->findSecurityAdvisorySource($advisory->source, $advisory->id)?->update($advisory);

        $now = new \DateTimeImmutable();

        $allSeverities = $this->sources->map(static fn (SecurityAdvisorySource $source) => $source->getSeverity())->toArray();
        if ($advisory->severity && (!$this->severity || !\in_array($this->severity, $allSeverities, true))) {
            $this->updatedAt = $now;
            $this->severity = $advisory->severity;
        }

        if (!\in_array($advisory->source, [null, $this->source], true)) {
            return;
        }

        if (
            $this->remoteId !== $advisory->id
            || $this->packageName !== $advisory->packageName
            || $this->title !== $advisory->title
            || $this->link !== $advisory->link
            || $this->cve !== $advisory->cve
            || $this->affectedVersions !== $advisory->affectedVersions
            || $this->reportedAt != $advisory->date
            || $this->composerRepository !== $advisory->composerRepository
            || ($this->severity !== $advisory->severity && $advisory->severity)
        ) {
            $this->updatedAt = $now;
        }

        $this->copyAdvisory($advisory, false);
    }

    private function copyAdvisory(RemoteSecurityAdvisory $advisory, bool $initialCopy): void
    {
        $this->remoteId = $advisory->id;
        $this->packageName = $advisory->packageName;
        $this->title = $advisory->title;
        $this->link = $advisory->link;
        $this->cve = $advisory->cve;
        $this->affectedVersions = $advisory->affectedVersions;
        $this->composerRepository = $advisory->composerRepository;

        // only update if the date is different to avoid ending up with a new datetime object which doctrine will want to update in the DB for nothing
        if ($initialCopy || $this->reportedAt != $advisory->date) {
            $this->reportedAt = $advisory->date;
        }

        if ($initialCopy && $advisory->severity) {
            $this->severity = $advisory->severity;
        }
    }

    public function getPackagistAdvisoryId(): string
    {
        return $this->packagistAdvisoryId;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getPackageName(): string
    {
        return $this->packageName;
    }

    public function getTitle(): string
    {
        return $this->title;
    }

    public function getLink(): ?string
    {
        return $this->link;
    }

    public function getCve(): ?string
    {
        // Cleanup invalid CVE ids stored in the database
        if (!AdvisoryParser::isValidCve($this->cve)) {
            $this->cve = null;
        }

        return $this->cve;
    }

    public function getAffectedVersions(): string
    {
        return $this->affectedVersions;
    }

    public function getSource(): string
    {
        return $this->source;
    }

    public function calculateDifferenceScore(RemoteSecurityAdvisory $advisory): int
    {
        // Regard advisories where CVE + package name match as identical as the remaining data on GitHub and FriendsOfPhp can be quite different
        if ($advisory->cve !== null && $advisory->cve === $this->getCve() && $advisory->packageName === $this->getPackageName()) {
            return 0;
        }

        $score = 0;
        if ($advisory->id !== $this->getRemoteId() && $this->getSource() === $advisory->source) {
            $score++;
        }

        if ($advisory->packageName !== $this->getPackageName()) {
            $score += 99;
        }

        if ($advisory->title !== $this->getTitle()) {
            $increase = 1;

            // Do not increase the score if the title was just renamed to add a CVE e.g. from CVE-2022-xxx to CVE-2022-99999999
            if (AdvisoryParser::titleWithoutCve($this->getTitle()) === AdvisoryParser::titleWithoutCve($advisory->title)) {
                $increase = 0;
            }

            $score += $increase;
        }

        if ($advisory->link !== $this->getLink() && !\in_array($this->getLink(), $advisory->references, true)) {
            $score++;
        }

        if ($advisory->cve !== $this->getCve()) {
            $score++;

            // CVE ID changed from not null to different not-null value
            if ($advisory->cve !== null && $this->getCve() !== null) {
                $score += 99;
            }
        }

        if ($advisory->affectedVersions !== $this->getAffectedVersions()) {
            $score++;
        }

        if ($advisory->composerRepository !== $this->composerRepository) {
            $score++;
        }

        if ($advisory->date != $this->reportedAt) {
            $score++;
        }

        return $score;
    }

    private function assignPackagistAdvisoryId(): void
    {
        $this->packagistAdvisoryId = IdGenerator::generateSecurityAdvisoryId();
    }

    public function getSeverity(): ?Severity
    {
        return $this->severity;
    }

    public function getWithdrawnAt(): ?\DateTimeImmutable
    {
        return $this->withdrawnAt;
    }

    public function isWithdrawn(): bool
    {
        return null !== $this->withdrawnAt;
    }

    public function hasSources(): bool
    {
        return !$this->sources->isEmpty();
    }

    public function hasActiveSources(): bool
    {
        return null !== $this->findActiveSource();
    }

    public function addSource(string $remoteId, string $source, ?Severity $severity, ?\DateTimeImmutable $publishedAt = null): void
    {
        if (null !== $this->findSecurityAdvisorySource($source, $remoteId)) {
            return;
        }

        // The source now lists the advisory under a different id. remoteId is part of the row's
        // identifier, so the old row is withdrawn rather than renamed.
        $this->findActiveSource($source)?->withdraw();

        $newSource = new SecurityAdvisorySource($this, $remoteId, $source, $severity, $publishedAt);
        if ($this->isWithdrawn()) {
            $newSource->withdraw();
        }

        $this->sources->add($newSource);

        // FriendsOfPhp source is curated by PHP developer, trust that data over data from GitHub
        if ($source === FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME) {
            $this->source = $source;
            $this->remoteId = $remoteId;
        }
    }

    /**
     * With {@see self::reinstateSource()} the only way the withdrawal state changes, keeping the
     * advisory withdrawn exactly when every one of its sources is.
     *
     * @return bool whether the advisory itself became withdrawn, i.e. this was its last listing source
     */
    public function withdrawSource(string $sourceName, ?string $remoteId = null): bool
    {
        $source = $this->findSecurityAdvisorySource($sourceName, $remoteId);
        if (null === $source || $source->isWithdrawn()) {
            return false;
        }

        $source->withdraw();

        // Withdrawing the main source that is used to synchronize all the data needs "promote" a new source to make sure the advisory keeps getting updated
        if ($sourceName === $this->source && $newMainSource = $this->findActiveSource()) {
            $this->remoteId = $newMainSource->getRemoteId();
            $this->source = $newMainSource->getSource();
        }

        if ($this->hasActiveSources() || null !== $this->withdrawnAt) {
            return false;
        }

        $this->withdrawnAt = new \DateTimeImmutable();
        $this->updatedAt = $this->withdrawnAt;

        return true;
    }

    public function reinstateSource(string $sourceName, string $remoteId): void
    {
        $source = $this->findSecurityAdvisorySource($sourceName, $remoteId);
        if (null === $source || !$source->isWithdrawn()) {
            return;
        }

        $source->reinstate();

        if (null !== $this->withdrawnAt) {
            $this->withdrawnAt = null;
            $this->updatedAt = new \DateTimeImmutable();
        }
    }

    private function findActiveSource(?string $sourceName = null): ?SecurityAdvisorySource
    {
        foreach ($this->sources as $source) {
            if (!$source->isWithdrawn() && (null === $sourceName || $source->getSource() === $sourceName)) {
                return $source;
            }
        }

        return null;
    }

    /**
     * @return Collection<int, SecurityAdvisorySource>&Selectable<int, SecurityAdvisorySource>
     */
    public function getSources(): Collection
    {
        return $this->sources;
    }

    /**
     * The id the source currently lists the advisory under, or the last one it used.
     */
    public function getSourceRemoteId(string $source): ?string
    {
        return $this->findSecurityAdvisorySource($source)?->getRemoteId();
    }

    /**
     * Every id the source ever listed the advisory under, active or withdrawn.
     *
     * @return list<string>
     */
    public function getSourceRemoteIds(string $source): array
    {
        $remoteIds = [];
        foreach ($this->sources as $advisorySource) {
            if ($advisorySource->getSource() === $source) {
                $remoteIds[] = $advisorySource->getRemoteId();
            }
        }

        return $remoteIds;
    }

    public function setupSource(): void
    {
        if (!$this->getSourceRemoteId($this->source)) {
            $this->addSource($this->remoteId, $this->source, null, $this->reportedAt);
        }
    }

    /**
     * With a remote id, that exact row. Without, the row the source currently lists the advisory
     * under, falling back to the last withdrawn one.
     */
    public function findSecurityAdvisorySource(string $search, ?string $remoteId = null): ?SecurityAdvisorySource
    {
        $fallback = null;
        foreach ($this->sources as $source) {
            if ($source->getSource() !== $search) {
                continue;
            }

            if (null !== $remoteId) {
                if ($source->getRemoteId() === $remoteId) {
                    return $source;
                }

                continue;
            }

            if (!$source->isWithdrawn()) {
                return $source;
            }

            $fallback = $source;
        }

        return $fallback;
    }

    /**
     * Nulls the CVE so its (packageName, activeCve) key can be flushed free before another advisory
     * takes it in the same run; {@see self::assignCve()} puts it back once that flush is done.
     */
    public function deferCve(): void
    {
        $this->cve = null;
    }

    public function assignCve(string $cve): void
    {
        $this->cve = $cve;
    }
}
