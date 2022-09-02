<?php declare(strict_types=1);

namespace App\Entity;

use Doctrine\ORM\Mapping as ORM;

#[ORM\Entity]
#[ORM\Table(name: 'security_advisory_source')]
#[ORM\Index(name: 'source_source_idx', columns: ['source'])]
class SecurityAdvisorySource
{
    #[ORM\Id]
    #[ORM\ManyToOne(targetEntity: SecurityAdvisory::class, inversedBy: 'sources')]
    #[ORM\JoinColumn(onDelete: 'CASCADE', nullable: false)]
    private SecurityAdvisory $securityAdvisory;

    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $remoteId;

    #[ORM\Id]
    #[ORM\Column(type: 'string')]
    private string $source;

    public function __construct(SecurityAdvisory $securityAdvisory, string $remoteId, string $source)
    {
        $this->securityAdvisory = $securityAdvisory;
        $this->remoteId = $remoteId;
        $this->source = $source;
    }

    public function getRemoteId(): string
    {
        return $this->remoteId;
    }

    public function getSource(): string
    {
        return $this->source;
    }
}
