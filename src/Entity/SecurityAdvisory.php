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

use App\SecurityAdvisory\AdvisoryIdGenerator;
use App\SecurityAdvisory\AdvisoryParser;
use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use DateTimeImmutable;
use Doctrine\Common\Collections\ArrayCollection;
use Doctrine\Common\Collections\Collection;
use Doctrine\Common\Collections\Selectable;
use Doctrine\ORM\Mapping as ORM;
use App\SecurityAdvisory\RemoteSecurityAdvisory;

#[ORM\Entity(repositoryClass: 'App\Entity\SecurityAdvisoryRepository')]
#[ORM\Table(name: 'security_advisory')]
#[ORM\UniqueConstraint(name: 'source_remoteid_package_idx', columns: ['source', 'remoteId', 'packageName'])]
#[ORM\UniqueConstraint(name: 'package_name_cve_idx', columns: ['packageName', 'cve'])]
#[ORM\Index(name: 'package_name_idx', columns: ['packageName'])]
#[ORM\Index(name: 'updated_at_idx', columns: ['updatedAt'])]
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
    private string|null $link = null;

    #[ORM\Column(type: 'string', nullable: true)]
    private string|null $cve = null;

    #[ORM\Column(type: 'text')]
    private string $affectedVersions;

    #[ORM\Column(type: 'string')]
    private string $source;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $reportedAt;

    #[ORM\Column(type: 'datetime_immutable')]
    private DateTimeImmutable $updatedAt;

    #[ORM\Column(type: 'string', nullable: true)]
    private string|null $composerRepository = null;

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

        $this->updatedAt = new DateTimeImmutable();

        $this->copyAdvisory($advisory, true);
        $this->addSource($this->remoteId, $source);
    }

    public function updateAdvisory(RemoteSecurityAdvisory $advisory): void
    {
        if (!in_array($advisory->source, [null, $this->source], true)) {
            return;
        }

        if (
            $this->remoteId !== $advisory->id ||
            $this->packageName !== $advisory->packageName ||
            $this->title !== $advisory->title ||
            $this->link !== $advisory->link ||
            $this->cve !== $advisory->cve ||
            $this->affectedVersions !== $advisory->affectedVersions ||
            $this->reportedAt != $advisory->date ||
            $this->composerRepository !== $advisory->composerRepository
        ) {
            $this->updatedAt = new DateTimeImmutable();
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
    }

    public function getPackagistAdvisoryId(): string
    {
        if (!isset($this->packagistAdvisoryId)) {
            $this->assignPackagistAdvisoryId();
        }

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
        if ($advisory->cve === $this->getCve() && $advisory->packageName === $this->getPackageName()) {
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

        if ($advisory->link !== $this->getLink() && !in_array($this->getLink(), $advisory->references, true)) {
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

    public function hasPackagistAdvisoryId(): bool
    {
        return (bool) $this->packagistAdvisoryId;
    }

    private function assignPackagistAdvisoryId(): void
    {
        $this->packagistAdvisoryId = AdvisoryIdGenerator::generate();
    }

    public function hasSources(): bool
    {
        return !$this->sources->isEmpty();
    }

    public function addSource(string $remoteId, string $source): void
    {
        if (null === $this->getSourceRemoteId($source)) {
            $this->sources->add(new SecurityAdvisorySource($this, $remoteId, $source));

            // FriendsOfPhp source is curated by PHP developer, trust that data over data from GitHub
            if ($source === FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME) {
                $this->source = $source;
                $this->remoteId = $remoteId;
            }
        }
    }

    public function removeSource(string $sourceName): bool
    {
        foreach ($this->sources as $source) {
            if ($source->getSource() === $sourceName) {
                $this->sources->removeElement($source);

                // Removing the main source that is used synchronize all the data needs "promote" a new source to make sure the advisory keeps getting updated
                if ($sourceName === $this->source && $newMainSource = $this->sources->first()) {
                    $this->remoteId = $newMainSource->getRemoteId();
                    $this->source = $newMainSource->getSource();
                }

                return true;
            }
        }

        return false;
    }

    /**
     * @return Collection<int, SecurityAdvisorySource>&Selectable<int, SecurityAdvisorySource>
     */
    public function getSources(): Collection
    {
        return $this->sources;
    }

    public function getSourceRemoteId(string $source): ?string
    {
        foreach ($this->sources as $advisorySource) {
            if ($advisorySource->getSource() === $source) {
                return $advisorySource->getRemoteId();
            }
        }

        return null;
    }

    public function setupSource(): void
    {
        if (!$this->getSourceRemoteId($this->source)) {
            $this->addSource($this->remoteId, $this->source);
        }
    }
}
