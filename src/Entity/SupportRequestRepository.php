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
use Symfony\Component\Uid\Ulid;

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
            // Escape the wildcards, or a search for "_" or "%" silently matches everything.
            $qb->andWhere("u.username LIKE :search ESCAPE '!' OR r.vendorName LIKE :search ESCAPE '!' OR r.packageNames LIKE :search ESCAPE '!'")
                ->setParameter('search', '%'.addcslashes($search, '%_!').'%');
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
            ->where('r.id IN (:ids)')
            ->setParameter('ids', array_map(static fn (SupportRequest $r): Ulid => $r->id, $requests))
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
