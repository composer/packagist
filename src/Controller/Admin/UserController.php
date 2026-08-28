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

use App\Controller\Controller;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\Entity\UserFreezeReason;
use App\Log\AuditLogEventType;
use App\QueryFilter\QueryFilterInterface;
use App\QueryFilter\User\FrozenStatusFilter;
use App\QueryFilter\User\GithubIdFilter;
use App\QueryFilter\User\GithubLinkedFilter;
use App\QueryFilter\User\RegisteredAfterFilter;
use App\QueryFilter\User\RegisteredBeforeFilter;
use App\QueryFilter\User\TwoFactorFilter;
use App\QueryFilter\User\UserSearchFilter;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_DISABLE_USERS')]
class UserController extends Controller
{
    /**
     * Admin user directory: every account, searchable by username/email and filterable by freeze
     * status (this doubles as the former dedicated frozen-users review queue).
     */
    #[Route(path: '/admin/users', name: 'admin_users', methods: ['GET'])]
    public function index(Request $req): Response
    {
        return $this->directory($req, FrozenStatusFilter::fromQuery($req->query), 'Users');
    }

    #[Route(path: '/admin/frozen-users', name: 'admin_frozen_users', methods: ['GET'])]
    public function frozenUsers(Request $req): Response
    {
        return $this->directory($req, FrozenStatusFilter::forReason(UserFreezeReason::Temporary), 'Frozen users');
    }

    private function directory(Request $req, FrozenStatusFilter $frozenFilter, string $heading): Response
    {
        /** @var QueryFilterInterface[] $filters */
        $filters = [
            UserSearchFilter::fromQuery($req->query),
            $frozenFilter,
            RegisteredAfterFilter::fromQuery($req->query),
            RegisteredBeforeFilter::fromQuery($req->query),
            TwoFactorFilter::fromQuery($req->query),
            GithubIdFilter::fromQuery($req->query),
            GithubLinkedFilter::fromQuery($req->query),
        ];

        $frozenView = $frozenFilter->narrowsToFrozen();

        $qb = $this->getEM()->getRepository(User::class)->getUsersQueryBuilder($frozenView);
        foreach ($filters as $filter) {
            $filter->filter($qb);
        }

        $users = new Pagerfanta(new QueryAdapter($qb, false, false));
        $users->setNormalizeOutOfRangePages(true);
        $users->setMaxPerPage(30);
        $users->setCurrentPage(max(1, $req->query->getInt('page', 1)));

        $selectedFilters = [];
        foreach ($filters as $filter) {
            $selectedFilters[$filter->getKey()] = $filter->getSelectedValue();
        }

        $freezeContext = [];
        if ($frozenView) {
            $ids = array_values(array_map(static fn (array $row): int => $row[0]->getId(), iterator_to_array($users)));
            $freezeContext = $this->latestFreezeAuditByUserId($ids);
        }

        return $this->render('admin/users.html.twig', [
            'heading' => $heading,
            'users' => $users,
            'selectedFilters' => $selectedFilters,
            'freezeReasons' => UserFreezeReason::cases(),
            'showFreezeContext' => $frozenView,
            'freezeContext' => $freezeContext,
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
            ->setParameter('type', AuditLogEventType::UserFrozen->value)
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
