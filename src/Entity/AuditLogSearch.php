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
 * Denormalized name -> audit record index powering the transparency-log user/actor/package filters.
 *
 * One row per (type, name) a record references at write time; `name` is stored lowercased so the
 * filters can do an indexed, case-insensitive lookup instead of scanning the JSON `attributes` blob.
 * Rows are written via raw DBAL in {@see AuditRecordRepository::indexSearchTerms()} and only ever
 * read through DQL sub-queries, so this class is a plain mapping shell (never instantiated in PHP).
 *
 * @see \App\Entity\AuditRecord::getSearchTerms()
 */
#[ORM\Entity]
#[ORM\Table(name: 'audit_log_search')]
#[ORM\Index(name: 'audit_log_id_idx', columns: ['auditLogId'])]
class AuditLogSearch
{
    public function __construct(
        // Identifier order defines the composite primary key; keep (type, name, auditLogId) so the
        // filters' WHERE type=? AND name=? lookup hits the PK directly (matches the migration).
        #[ORM\Id]
        #[ORM\Column(length: 16)]
        public readonly string $type,

        #[ORM\Id]
        #[ORM\Column(length: 255)]
        public readonly string $name,

        #[ORM\Id]
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $auditLogId,
    ) {
    }
}
