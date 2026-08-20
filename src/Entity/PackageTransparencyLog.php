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

use App\Audit\TransparencyLogType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * A public, per-package, append-only transparency-log entry projected asynchronously from a package
 * relevant {@see AuditRecord} row (see ProjectTransparencyLogCommand).
 *
 * Rows are appended in source-ULID (chronological) order behind a safety-lag window, so
 * {@see self::$leafIndex} is a gapless sequence in event order.
 */
#[ORM\Entity(repositoryClass: PackageTransparencyLogRepository::class)]
#[ORM\Table(name: 'package_transparency_log')]
#[ORM\UniqueConstraint(name: 'source_package_uniq', columns: ['sourceAuditLogId', 'packageId'])]
#[ORM\UniqueConstraint(name: 'leaf_index_uniq', columns: ['leafIndex'])]
// Every public read filters on one of these columns and then sorts by leafIndex, so the sort column
// is part of each index: without it MySQL filesorts every matching row of a package that may hold one
// entry per version ever published.
#[ORM\Index(name: 'package_leaf_idx', columns: ['packageId', 'leafIndex'])]
#[ORM\Index(name: 'vendor_leaf_idx', columns: ['vendor', 'leafIndex'])]
#[ORM\Index(name: 'user_leaf_idx', columns: ['userId', 'leafIndex'])]
#[ORM\Index(name: 'type_leaf_idx', columns: ['type', 'leafIndex'])]
#[ORM\Index(name: 'datetime_idx', columns: ['datetime'])]
class PackageTransparencyLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid')]
    public readonly Ulid $id;

    private function __construct(
        /**
         * The `audit_log.id` this entry was projected from. Drives the projection cursor
         * (MAX(sourceAuditLogId)) and, together with packageId, the idempotency (dedupe) key: one
         * source event fans out to at most one row per package.
         */
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $sourceAuditLogId,

        /**
         * Gapless append-only position in the log, assigned in source-ULID order.
         */
        #[ORM\Column(options: ['unsigned' => true])]
        public readonly int $leafIndex,

        #[ORM\Column(length: 64)]
        public readonly TransparencyLogType $type,

        /**
         * PII-scrubbed copy of the source audit record's attributes.
         *
         * @var array<string, mixed>
         */
        #[ORM\Column(type: Types::JSON)]
        public readonly array $attributes,

        #[ORM\Column]
        public readonly \DateTimeImmutable $datetime,

        #[ORM\Column(nullable: true)]
        public readonly ?int $actorId = null,
        #[ORM\Column(nullable: true)]
        public readonly ?string $vendor = null,
        #[ORM\Column(nullable: true)]
        public readonly ?int $packageId = null,
        #[ORM\Column(nullable: true)]
        public readonly ?int $userId = null,
        #[ORM\Column(type: 'ulid', nullable: true)]
        public readonly ?Ulid $organizationId = null,

        /**
         * Signable per-leaf hash. Reserved for the future hashing/publication layer; always null now.
         */
        #[ORM\Column(type: Types::BINARY, length: 32, nullable: true)]
        public readonly mixed $leafHash = null,
    ) {
        $this->id = new Ulid();
    }

    /**
     * Builds a transparency-log entry from a source audit record, targeting a specific package.
     *
     * Attributes must already be scrubbed by {@see \App\Audit\TransparencyLogScrubber}.
     *
     * @param array<string, mixed> $scrubbedAttributes
     */
    public static function project(AuditRecord $source, TransparencyLogType $type, int $leafIndex, array $scrubbedAttributes, ?int $packageId, ?string $vendor): self
    {
        return new self(
            sourceAuditLogId: $source->id,
            leafIndex: $leafIndex,
            type: $type,
            attributes: $scrubbedAttributes,
            datetime: $source->datetime,
            actorId: $source->actorId,
            vendor: $vendor,
            packageId: $packageId,
            userId: $source->userId,
            organizationId: $source->organizationId,
        );
    }
}
