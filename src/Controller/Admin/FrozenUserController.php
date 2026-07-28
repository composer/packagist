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

namespace App\Controller\Admin;

use App\Audit\AuditRecordType;
use App\Controller\Controller;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\Entity\UserFreezeReason;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_DISABLE_USERS')]
class FrozenUserController extends Controller
{
    /**
     * Review queue for frozen accounts (Temporary holds in particular, which are meant to be
     * revisited). Any reason can be filtered on; the freeze context per row is derived from the
     * latest UserFrozen audit record.
     */
    #[Route(path: '/admin/frozen-users', name: 'admin_frozen_users', methods: ['GET'])]
    public function index(Request $req): Response
    {
        $reason = UserFreezeReason::tryFrom($req->query->getString('reason'));

        $qb = $this->getEM()->getRepository(User::class)->getFrozenUsersQueryBuilder($reason);

        // Output walkers keep the count query valid despite the HIDDEN ordering subquery.
        $users = new Pagerfanta(new QueryAdapter($qb, false, true));
        $users->setNormalizeOutOfRangePages(true);
        $users->setMaxPerPage(20);
        $users->setCurrentPage(max(1, $req->query->getInt('page', 1)));

        $userIds = array_values(array_map(static fn (User $user): int => $user->getId(), iterator_to_array($users)));

        return $this->render('admin/frozen_users.html.twig', [
            'users' => $users,
            'freezeContext' => $this->latestFreezeAuditByUserId($userIds),
            'reasons' => UserFreezeReason::cases(),
            'selectedReason' => $reason,
        ]);
    }

    /**
     * @param list<int> $userIds
     *
     * @return array<int, AuditRecord> the most recent UserFrozen record per user id
     */
    private function latestFreezeAuditByUserId(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        /** @var list<AuditRecord> $records */
        $records = $this->getEM()->getRepository(AuditRecord::class)->createQueryBuilder('a')
            ->where('a.type = :type')
            ->andWhere('a.userId IN (:ids)')
            ->setParameter('type', AuditRecordType::UserFrozen->value)
            ->setParameter('ids', $userIds)
            ->orderBy('a.datetime', 'DESC')
            ->getQuery()->getResult();

        $latestByUserId = [];
        foreach ($records as $record) {
            if ($record->userId !== null && !isset($latestByUserId[$record->userId])) {
                $latestByUserId[$record->userId] = $record;
            }
        }

        return $latestByUserId;
    }
}
