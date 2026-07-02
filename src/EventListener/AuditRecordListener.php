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

namespace App\EventListener;

use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Service\AuditRecordsManager;
use Doctrine\Bundle\DoctrineBundle\Attribute\AsEntityListener;
use Doctrine\ORM\Event\PostPersistEventArgs;
use Doctrine\ORM\Event\PrePersistEventArgs;

#[AsEntityListener(event: 'prePersist', entity: AuditRecord::class)]
#[AsEntityListener(event: 'postPersist', entity: AuditRecord::class)]
class AuditRecordListener
{
    public function __construct(
        private readonly AuditRecordsManager $auditRecordsManager,
    ) {
    }

    public function prePersist(AuditRecord $record, PrePersistEventArgs $args): void
    {
        $this->auditRecordsManager->enrichWithClientIP($record);
    }

    public function postPersist(AuditRecord $record, PostPersistEventArgs $args): void
    {
        // Mirror AuditRecordRepository::insert()'s search indexing for records persisted via the ORM.
        $repository = $args->getObjectManager()->getRepository(AuditRecord::class);
        \assert($repository instanceof AuditRecordRepository);
        $repository->indexSearchTerms($record);
    }
}
