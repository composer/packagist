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
use Symfony\Component\Uid\Ulid;

/**
 * Transactional outbox for the package transparency log. A row is written in the same transaction as its
 * `audit_log` row and deleted in the same transaction as the entries projected from it.
 *
 * Only projectable types are enqueued.
 *
 * @see \App\Service\TransparencyLogProjector
 */
#[ORM\Entity(repositoryClass: PackageTransparencyLogQueueRepository::class)]
#[ORM\Table(name: 'package_transparency_log_queue')]
class PackageTransparencyLogQueue
{
    public function __construct(
        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $auditLogId,
    ) {
    }
}
