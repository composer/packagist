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
