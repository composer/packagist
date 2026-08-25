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
 * Transactional outbox for the package transparency log: one row per audit record still waiting to
 * be projected.
 *
 * A row is written in the same transaction as its `audit_log` row and deleted in the same
 * transaction as the entries projected from it. `audit_log.id` is a ULID assigned when the
 * {@see AuditRecord} is *constructed*, not when its transaction commits, so a long-running
 * transaction can commit a row whose id is lower than rows committed before it. That row still has
 * its queue row when it is committed, so the projector picks it up then.
 *
 * Only projectable types are enqueued, so the table holds exactly what is pending. Rows are written,
 * read and deleted via raw DBAL in {@see PackageTransparencyLogQueueRepository}.
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
