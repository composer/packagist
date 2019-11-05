<?php declare(strict_types=1);

namespace Packagist\WebBundle\Entity;

use Doctrine\ORM\Mapping as ORM;
use Packagist\WebBundle\SecurityAdvisory\RemoteSecurityAdvisory;

/**
 * @ORM\Entity(repositoryClass="Packagist\WebBundle\Entity\SecurityAdvisoryRepository")
 * @ORM\Table(
 *     name="security_advisory",
 *     uniqueConstraints={@ORM\UniqueConstraint(name="source_packagename_idx", columns={"source","packageName"})},
 *     indexes={
 *         @ORM\Index(name="package_name_idx",columns={"packageName"})
 *     }
 * )
 */
class SecurityAdvisory
{
    /**
     * @ORM\Id
     * @ORM\Column(type="integer")
     * @ORM\GeneratedValue(strategy="AUTO")
     */
    private $id;

    /**
     * @ORM\Column(type="string")
     */
    private $remoteId;

    /**
     * @ORM\Column(type="string")
     */
    private $packageName;

    /**
     * @ORM\Column(type="string")
     */
    private $title;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $link;

    /**
     * @ORM\Column(type="string", nullable=true)
     */
    private $cve;

    /**
     * @ORM\Column(type="string")
     */
    private $affectedVersions;

    /**
     * @ORM\Column(type="string")
     */
    private $source;

    public function __construct(RemoteSecurityAdvisory $advisory, string $source)
    {
        $this->source = $source;
        $this->updateAdvisory($advisory);
    }

    public function updateAdvisory(RemoteSecurityAdvisory $advisory): void
    {
        $this->remoteId = $advisory->getId();
        $this->packageName = $advisory->getPackageName();
        $this->title = $advisory->getTitle();
        $this->link = $advisory->getLink();
        $this->cve = $advisory->getCve();
        $this->affectedVersions = $advisory->getAffectedVersions();
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }
}
