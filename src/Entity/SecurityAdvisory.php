<?php declare(strict_types=1);

namespace App\Entity;

use App\SecurityAdvisory\AdvisoryIdGenerator;
use App\SecurityAdvisory\AdvisoryParser;
use Composer\Pcre\Preg;
use DateTimeInterface;
use Doctrine\ORM\Mapping as ORM;
use App\SecurityAdvisory\RemoteSecurityAdvisory;

/**
 * @ORM\Entity(repositoryClass="App\Entity\SecurityAdvisoryRepository")
 * @ORM\Table(
 *     name="security_advisory",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="source_remoteid_idx", columns={"source","remoteId"})},
 *     indexes={
 *         @ORM\Index(name="package_name_idx",columns={"packageName"}),
 *         @ORM\Index(name="updated_at_idx",columns={"updatedAt"})
 *     }
 * )
 */
class SecurityAdvisory
{
    public const PACKAGIST_ORG = 'https://packagist.org';

    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private int $id;

    /**
     * @ORM\Column(type="string", unique=true)
     */
    private string $packagistAdvisoryId;

    /**
     * @ORM\Column(type="string")
     */
    private string $remoteId;

    /**
     * @ORM\Column(type="string")
     */
    private string $packageName;

    /**
     * @ORM\Column(type="string")
     */
    private string $title;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private string|null $link = null;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private string|null $cve = null;

    /**
     * @ORM\Column(type="text")
     */
    private string $affectedVersions;

    /**
     * @ORM\Column(type="string")
     */
    private string $source;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTimeInterface $reportedAt;

    /**
     * @ORM\Column(type="datetime")
     */
    private DateTimeInterface $updatedAt;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private string|null $composerRepository = null;

    public function __construct(RemoteSecurityAdvisory $advisory, string $source)
    {
        $this->source = $source;
        $this->assignPackagistAdvisoryId();
        $this->updateAdvisory($advisory);
    }

    public function updateAdvisory(RemoteSecurityAdvisory $advisory): void
    {
        if (
            $this->remoteId !== $advisory->getId() ||
            $this->packageName !== $advisory->getPackageName() ||
            $this->title !== $advisory->getTitle() ||
            $this->link !== $advisory->getLink() ||
            $this->cve !== $advisory->getCve() ||
            $this->affectedVersions !== $advisory->getAffectedVersions() ||
            $this->reportedAt != $advisory->getDate() ||
            $this->composerRepository !== $advisory->getComposerRepository()
        ) {
            $this->updatedAt = new \DateTime();
            $this->reportedAt = $advisory->getDate();
        }

        $this->remoteId = $advisory->getId();
        $this->packageName = $advisory->getPackageName();
        $this->title = $advisory->getTitle();
        $this->link = $advisory->getLink();
        $this->cve = $advisory->getCve();
        $this->affectedVersions = $advisory->getAffectedVersions();
        $this->composerRepository = $advisory->getComposerRepository();
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
        $score = 0;
        if ($advisory->getId() !== $this->getRemoteId()) {
            $score++;
        }

        if ($advisory->getPackageName() !== $this->getPackageName()) {
            $score += 99;
        }

        if ($advisory->getTitle() !== $this->getTitle()) {
            $increase = 1;

            // Do not increase the score if the title was just renamed to add a CVE e.g. from CVE-2022-xxx to CVE-2022-99999999
            if (AdvisoryParser::titleWithoutCve($this->getTitle()) === AdvisoryParser::titleWithoutCve($advisory->getTitle())) {
                $increase = 0;
            }

            $score += $increase;
        }

        if ($advisory->getLink() !== $this->getLink()) {
            $score++;
        }

        if ($advisory->getCve() !== $this->getCve()) {
            $score++;

            // CVE ID changed from not null to different not-null value
            if ($advisory->getCve() !== null && $this->getCve() !== null) {
                $score += 99;
            }
        }

        if ($advisory->getAffectedVersions() !== $this->getAffectedVersions()) {
            $score++;
        }

        if ($advisory->getComposerRepository() !== $this->composerRepository) {
            $score++;
        }

        if ($advisory->getDate() != $this->reportedAt) {
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
}
