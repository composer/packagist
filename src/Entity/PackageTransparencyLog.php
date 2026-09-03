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

use App\Log\TransparencyLogEventType;
use App\Log\TransparencyLogSearchType;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping as ORM;
use Symfony\Component\Uid\Ulid;

/**
 * A public, per-package, append-only transparency-log entry projected from an {@see AuditRecord} row
 * by {@see \App\Service\TransparencyLogProjector}.
 *
 * {@see self::$leafIndex} numbers the rows in the order they were inserted, which is not the order
 * the events happened: a source row committed late is appended at the end, with a $datetime older
 * than the leaf before it.
 */
#[ORM\Entity(repositoryClass: PackageTransparencyLogRepository::class)]
#[ORM\Table(name: 'package_transparency_log')]
#[ORM\UniqueConstraint(name: 'source_package_uniq', columns: ['sourceAuditLogId', 'packageId'])]
#[ORM\UniqueConstraint(name: 'leaf_index_uniq', columns: ['leafIndex'])]
// Every public read filters on one of these columns and sorts by (datetime, leafIndex)
// ({@see PackageTransparencyLogRepository::getQueryBuilderForPublicView()}), so the sort columns are
// part of each index, otherwise MySQL filesorts every matching row.
#[ORM\Index(name: 'package_name_datetime_idx', columns: ['packageName', 'datetime', 'leafIndex'])]
#[ORM\Index(name: 'vendor_datetime_idx', columns: ['vendor', 'datetime', 'leafIndex'])]
#[ORM\Index(name: 'user_datetime_idx', columns: ['userId', 'datetime', 'leafIndex'])]
#[ORM\Index(name: 'type_datetime_idx', columns: ['type', 'datetime', 'leafIndex'])]
#[ORM\Index(name: 'datetime_leaf_idx', columns: ['datetime', 'leafIndex'])]
class PackageTransparencyLog
{
    #[ORM\Id]
    #[ORM\Column(type: 'ulid')]
    public readonly Ulid $id;

    private function __construct(
        /**
         * The `audit_log.id` this entry was projected from. Together with packageId it is the
         * dedupe key: one source event produces at most one row per package.
         */
        #[ORM\Column(type: 'ulid')]
        public readonly Ulid $sourceAuditLogId,

        /**
         * Position in the log. Rows are only ever appended and the numbers have no gaps.
         */
        #[ORM\Column(options: ['unsigned' => true])]
        public readonly int $leafIndex,

        #[ORM\Column(length: 64)]
        public readonly TransparencyLogEventType $type,

        /**
         * PII-scrubbed copy of the source audit record's attributes.
         *
         * @var array<string, mixed>
         */
        #[ORM\Column(type: Types::JSON)]
        public readonly array $attributes,

        #[ORM\Column]
        public readonly \DateTimeImmutable $datetime,

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
         * Per-leaf hash for the future hashing/publication layer. Always null for now.
         */
        #[ORM\Column(type: Types::BINARY, length: 32, nullable: true)]
        public readonly ?string $leafHash = null,
    ) {
        $this->id = new Ulid();
    }

    /**
     * The people this entry names, one term for each person and role, for
     * {@see PackageTransparencyLogSearch}.
     *
     * A person is indexed only if both the id and the username are known, so string actors such as
     * 'automation' are skipped.
     *
     * `primaryRow` is true on the first term of each account, so a search by account finds the entry once.
     *
     * @return list<array{type: TransparencyLogSearchType, name: string, userId: int, primaryRow: bool}>
     */
    public function getSearchTerms(): array
    {
        $terms = [];
        $add = static function (TransparencyLogSearchType $type, mixed $person) use (&$terms): void {
            if (!\is_array($person)) {
                return;
            }

            $id = $person['id'] ?? null;
            $username = $person['username'] ?? null;
            if (!\is_int($id) || !\is_string($username) || $username === '') {
                return;
            }

            $lower = mb_strtolower($username);
            // a maintainer can be in both lists of a transfer
            $terms[$type->value."\0".$lower] = ['type' => $type, 'name' => $lower, 'userId' => $id];
        };

        $add(TransparencyLogSearchType::User, $this->attributes['user'] ?? null);

        // the maintainers before and after a transfer
        foreach (['previous_maintainers', 'current_maintainers'] as $key) {
            $maintainers = $this->attributes[$key] ?? null;
            if (\is_array($maintainers)) {
                foreach ($maintainers as $maintainer) {
                    $add(TransparencyLogSearchType::User, $maintainer);
                }
            }
        }

        $add(TransparencyLogSearchType::Actor, $this->attributes['actor'] ?? null);

        $seenUserIds = [];
        $result = [];
        foreach ($terms as $term) {
            $result[] = $term + ['primaryRow' => !isset($seenUserIds[$term['userId']])];
            $seenUserIds[$term['userId']] = true;
        }

        return $result;
    }

    /**
     * Builds an entry from a source audit record for one package. Attributes must already be
     * scrubbed by {@see \App\Log\TransparencyLogScrubber}.
     *
     * @param array<string, mixed> $scrubbedAttributes
     */
    public static function project(AuditRecord $source, TransparencyLogEventType $type, int $leafIndex, array $scrubbedAttributes, int $packageId, ?string $vendor, string $packageName): self
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
