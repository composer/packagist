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
 * {@see self::$leafIndex} numbers the rows in the order the projector inserted them, which is not always
 * chronological: a source row committed after newer ones were already projected is appended at the
 * end, and therefore carries a $datetime older than the leaf before it. leafIndex must stay append-only and immutable
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
         * The `audit_log.id` this entry was projected from. Together with packageId it is the
         * idempotency (dedupe) key: one source event fans out to at most one row per package. Its
         * MAX() is also the projector's late-arrival high-water mark, which is used only for logging
         * ({@see PackageTransparencyLogRepository::getHighestProjectedSourceId()}).
         */
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $sourceAuditLogId,

        /**
         * Gapless append-only position in package_transparency_log, assigned in the order the
         * projector inserts entries, which is source-ULID order except for late-committing rows.
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

        /**
         * Every entry belongs to exactly one package: package-native events carry their own
         * packageId and account events fan out to ids read from the database. NOT NULL is load
         * bearing, because MySQL treats NULLs as distinct, so (sourceAuditLogId, NULL) would not
         * collide in source_package_uniq and a retried projection could append a second,
         * permanently immutable leaf for the same event.
         */
        #[ORM\Column]
        public readonly int $packageId,

        #[ORM\Column(length: 255)]
        public readonly string $packageName,

        #[ORM\Column(nullable: true)]
        public readonly ?int $actorId = null,
        #[ORM\Column(nullable: true)]
        public readonly ?string $vendor = null,
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
    public static function project(AuditRecord $source, TransparencyLogType $type, int $leafIndex, array $scrubbedAttributes, int $packageId, ?string $vendor, string $packageName): self
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
            packageName: $packageName,
            userId: $source->userId,
            organizationId: $source->organizationId,
        );
    }
}
