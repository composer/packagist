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

namespace App\Controller;

use App\Entity\AuditRecordRepository;
use App\Entity\User;
use App\Log\AuditLogEventType;
use App\Log\Display\AuditLogDisplayFactory;
use App\QueryFilter\AuditLog\DateTimeFromFilter;
use App\QueryFilter\AuditLog\DateTimeToFilter;
use App\QueryFilter\AuditLog\EventTypeFilter;
use App\QueryFilter\QueryFilterInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class UserAuditLogController extends Controller
{
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/profile/security/audit-log', name: 'security_audit_log', methods: ['GET'])]
    public function auditLog(Request $request, #[CurrentUser] User $user, AuditRecordRepository $auditRecordRepository, AuditLogDisplayFactory $displayFactory): Response
    {
        $dateTimeFromFilter = DateTimeFromFilter::fromQuery($request->query);
        $dateTimeToFilter = DateTimeToFilter::fromQuery($request->query);

        /** @var QueryFilterInterface[] $filters */
        $filters = [
            EventTypeFilter::fromQuery($request->query),
            $dateTimeFromFilter,
            $dateTimeToFilter,
        ];

        $qb = $auditRecordRepository->createQueryBuilderForUser($user);

        $selectedFilters = [];
        foreach ($filters as $filter) {
            $filter->filter($qb);
            $selectedFilters[$filter->getKey()] = $filter->getSelectedValue();
        }

        $auditLogs = new Pagerfanta(new QueryAdapter($qb, false, false));
        $auditLogs->setNormalizeOutOfRangePages(true);
        $auditLogs->setMaxPerPage(20);
        $auditLogs->setCurrentPage(max(1, $request->query->getInt('page', 1)));

        return $this->render('user/audit_log.html.twig', [
            'user' => $user,
            'auditLogDisplays' => $displayFactory->build($auditLogs),
            'auditLogPaginator' => $auditLogs,
            'groupedTypes' => AuditLogEventType::casesByCategory(),
            'selectedFilters' => $selectedFilters,
            'dateTimeFromFilter' => $dateTimeFromFilter,
            'dateTimeToFilter' => $dateTimeToFilter,
        ]);
    }
}
