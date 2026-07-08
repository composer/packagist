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

use App\Service\AuditRecordsManager;
use App\Util\IpAddress;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\DBAL\Types\Types;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bridge\Doctrine\Types\UlidType;

/**
 * @extends ServiceEntityRepository<AuditRecord>
 */
class AuditRecordRepository extends ServiceEntityRepository
{
    public function __construct(
        ManagerRegistry $registry,
        private readonly AuditRecordsManager $auditRecordsManager,
    ) {
        parent::__construct($registry, AuditRecord::class);
    }

    /**
     * Performs a direct insert not requiring usage of the ORM so it can be used within ORM lifecycle listeners
     */
    public function insert(AuditRecord $record): void
    {
        $this->auditRecordsManager->enrichWithClientIP($record);

        $this->getEntityManager()->getConnection()->insert('audit_log', [
            'id' => $record->id,
            'datetime' => $record->datetime,
            'type' => $record->type->value,
            'attributes' => $record->attributes,
            'actorId' => $record->actorId,
            'vendor' => $record->vendor,
            'packageId' => $record->packageId,
            'userId' => $record->userId,
            'ip' => IpAddress::stringToBinary($record->ip),
            'organizationId' => $record->organizationId,
        ], [
            'id' => UlidType::NAME,
            'datetime' => Types::DATETIME_IMMUTABLE,
            'attributes' => Types::JSON,
            'organizationId' => UlidType::NAME,
        ]);

        $this->indexSearchTerms($record);
    }

    /**
     * Denormalizes the record's searchable names into audit_log_search so the transparency-log
     * user/actor/package filters can do an indexed lookup instead of scanning the JSON attributes.
     *
     * Called from {@see insert()} for the direct-insert path and from the postPersist listener for
     * the ORM path (the two paths are disjoint). Idempotent via INSERT IGNORE on the primary key.
     */
    public function indexSearchTerms(AuditRecord $record): void
    {
        $terms = $record->getSearchTerms();
        if (\count($terms) === 0) {
            return;
        }

        $idBinary = $record->id->toBinary();
        $placeholders = [];
        $params = [];
        foreach ($terms as $term) {
            $placeholders[] = '(?, ?, ?)';
            $params[] = $idBinary;
            $params[] = $term['type'];
            $params[] = $term['name'];
        }

        $this->getEntityManager()->getConnection()->executeStatement(
            'INSERT IGNORE INTO audit_log_search (auditLogId, type, name) VALUES '.implode(', ', $placeholders),
            $params,
        );
    }
}
