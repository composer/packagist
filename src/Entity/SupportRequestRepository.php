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

use App\Support\SupportRequestStatus;
use App\Support\SupportRequestType;
use Doctrine\Bundle\DoctrineBundle\Repository\ServiceEntityRepository;
use Doctrine\ORM\QueryBuilder;
use Doctrine\Persistence\ManagerRegistry;

/**
 * @extends ServiceEntityRepository<SupportRequest>
 */
class SupportRequestRepository extends ServiceEntityRepository
{
    public function __construct(ManagerRegistry $registry)
    {
        parent::__construct($registry, SupportRequest::class);
    }

    public function findOneByPublicId(string $publicId): ?SupportRequest
    {
        return $this->findOneBy(['publicId' => $publicId]);
    }

    /**
     * The open request of this type for this user, if any. Mirrors the support_request_open_uniq
     * invariant, so callers can report the existing request instead of racing into a duplicate.
     */
    public function findOpen(User $user, SupportRequestType $type): ?SupportRequest
    {
        return $this->findOneBy([
            'user' => $user,
            'type' => $type->value,
            'status' => SupportRequestStatus::Open->value,
        ]);
    }

    /**
     * @param list<SupportRequestType> $visibleTypes types the viewer is allowed to see
     */
    public function createQueueQueryBuilder(array $visibleTypes, ?SupportRequestStatus $status, ?SupportRequestType $type, string $search): QueryBuilder
    {
        $qb = $this->createQueryBuilder('r')
            ->join('r.user', 'u')
            ->addSelect('u')
            // Oldest un-actioned first: this is a work queue, not a reference table.
            ->orderBy('r.createdAt', 'ASC')
            ->andWhere('r.type IN (:visibleTypes)')
            ->setParameter('visibleTypes', array_map(static fn (SupportRequestType $t): string => $t->value, $visibleTypes));

        if ($status !== null) {
            $qb->andWhere('r.status = :status')->setParameter('status', $status->value);
        }

        if ($type !== null) {
            $qb->andWhere('r.type = :type')->setParameter('type', $type->value);
        }

        if ($search !== '') {
            // Escaped with the character the clause declares, not a backslash: with ESCAPE '!' a
            // backslash is an ordinary character, so addcslashes() would leave % and _ live. The
            // escape character goes first in the replacement so it is not applied twice.
            $qb->andWhere("u.username LIKE :search ESCAPE '!' OR r.vendorName LIKE :search ESCAPE '!' OR r.packageNames LIKE :search ESCAPE '!'")
                ->setParameter('search', '%'.str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search).'%');
        }

        return $qb;
    }

    /**
     * @param list<SupportRequestType> $visibleTypes
     */
    public function countOpen(array $visibleTypes): int
    {
        if ($visibleTypes === []) {
            return 0;
        }

        return (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.status = :status')
            ->andWhere('r.type IN (:visibleTypes)')
            ->setParameter('status', SupportRequestStatus::Open->value)
            ->setParameter('visibleTypes', array_map(static fn (SupportRequestType $t): string => $t->value, $visibleTypes))
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Note counts for the rows on one queue page, so the list does not hydrate every message body of
     * every request just to show a number.
     *
     * @param list<SupportRequest> $requests
     *
     * @return array<string, int> note count keyed by public id
     */
    public function countMessagesFor(array $requests): array
    {
        if ($requests === []) {
            return [];
        }

        /** @var list<array{publicId: string, total: int}> $rows */
        $rows = $this->createQueryBuilder('r')
            ->select('r.publicId AS publicId, COUNT(m.id) AS total')
            ->leftJoin('r.messages', 'm')
            // Keyed on publicId, not id: an array of Ulid gets no parameter type, so ParameterTypeInferer
            // binds each one as its base32 string against a BINARY(16) column and matches nothing.
            ->where('r.publicId IN (:publicIds)')
            ->setParameter('publicIds', array_map(static fn (SupportRequest $r): string => $r->publicId, $requests))
            ->groupBy('r.publicId')
            ->getQuery()
            ->getResult();

        $counts = [];
        foreach ($rows as $row) {
            $counts[$row['publicId']] = (int) $row['total'];
        }

        return $counts;
    }

    /**
     * Whether the row is resolved as the database has it, ignoring the managed entity, which the
     * caller may have just lost a race against. A COUNT rather than a status select so the enum
     * never has to survive scalar hydration, and refresh() is avoided because it cascades.
     */
    public function isResolved(SupportRequest $request): bool
    {
        return 1 === (int) $this->createQueryBuilder('r')
            ->select('COUNT(r.id)')
            ->where('r.publicId = :publicId')
            ->andWhere('r.status = :resolved')
            ->setParameter('publicId', $request->publicId)
            ->setParameter('resolved', SupportRequestStatus::Resolved->value)
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Whether this request was already escalated as disputed. The cancellation link stays live for
     * good, so without this every repeat click would mail the admins again.
     */
    public function hasDisputeNote(SupportRequest $request): bool
    {
        return 0 < (int) $this->createQueryBuilder('r')
            ->select('COUNT(m.id)')
            ->join('r.messages', 'm')
            ->where('r.publicId = :publicId')
            ->andWhere('m.contents LIKE :marker')
            ->setParameter('publicId', $request->publicId)
            ->setParameter('marker', SupportRequestMessage::DISPUTE_NOTE_PREFIX.'%')
            ->getQuery()
            ->getSingleScalarResult();
    }

    /**
     * Prior lost-2FA requests for this account, newest first, excluding the one being reviewed.
     * A repeat requester — especially one whose earlier request the owner cancelled — is a signal.
     *
     * @return list<SupportRequest>
     */
    public function findPreviousTwoFactorRequests(SupportRequest $current): array
    {
        /** @var list<SupportRequest> $requests */
        $requests = $this->createQueryBuilder('r')
            ->where('r.user = :user')
            ->andWhere('r.type = :type')
            ->andWhere('r.id != :current')
            ->setParameter('user', $current->user)
            ->setParameter('type', SupportRequestType::LostTwoFactor->value)
            ->setParameter('current', $current->id, 'ulid')
            ->orderBy('r.createdAt', 'DESC')
            ->setMaxResults(10)
            ->getQuery()
            ->getResult();

        return $requests;
    }
}
