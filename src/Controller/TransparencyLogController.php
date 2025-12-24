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

use App\Audit\AuditRecordType;
use App\Audit\Display\AuditLogDisplayFactory;
use App\Entity\AuditRecordRepository;
use App\QueryFilter\AuditLog\ActorFilter;
use App\QueryFilter\AuditLog\AuditRecordTypeFilter;
use App\QueryFilter\AuditLog\DateTimeFromFilter;
use App\QueryFilter\AuditLog\DateTimeToFilter;
use App\QueryFilter\AuditLog\PackageNameFilter;
use App\QueryFilter\AuditLog\UserFilter;
use App\QueryFilter\AuditLog\VendorFilter;
use App\QueryFilter\QueryFilterInterface;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TransparencyLogController extends Controller
{
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/transparency-log', name: 'view_audit_logs')]
    public function viewAuditLogs(Request $request, AuditRecordRepository $auditRecordRepository, AuditLogDisplayFactory $displayFactory): Response
    {
        $isAdmin = $this->isGranted('ROLE_ADMIN');

        $dateTimeFromFilter = DateTimeFromFilter::fromQuery($request->query);
        $dateTimeToFilter = DateTimeToFilter::fromQuery($request->query);

        /** @var QueryFilterInterface[] $filters */
        $filters = [
            AuditRecordTypeFilter::fromQuery($request->query),
            ActorFilter::fromQuery($request->query, 'actor', $isAdmin),
            UserFilter::fromQuery($request->query, 'user', $isAdmin),
            VendorFilter::fromQuery($request->query, 'vendor', $isAdmin),
            PackageNameFilter::fromQuery($request->query, 'package', $isAdmin),
            $dateTimeFromFilter,
            $dateTimeToFilter,
        ];

        $qb = $auditRecordRepository->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC');

        foreach ($filters as $filter) {
            $filter->filter($qb);
        }

        // Don't display 2FA events in the result list initially
        $qb->andWhere('a.type NOT IN (:hidden_types)')
            ->setParameter('hidden_types', [
                AuditRecordType::TwoFaAuthenticationActivated->value,
                AuditRecordType::TwoFaAuthenticationDeactivated->value,
            ]);

        $auditLogs = new Pagerfanta(new QueryAdapter($qb, true));
        $auditLogs->setNormalizeOutOfRangePages(true);
        $auditLogs->setMaxPerPage(20);
        $auditLogs->setCurrentPage(max(1, $request->query->getInt('page', 1)));

        $selectedFilters = [];
        foreach ($filters as $filter) {
            $selectedFilters[$filter->getKey()] = $filter->getSelectedValue();
        }

        // Group types by category in desired order
        $categoryOrder = ['ownership', 'package', 'version', 'user'];
        $groupedTypes = [];
        foreach (AuditRecordType::cases() as $type) {
            // Don't display 2FA events in the type filter initially
            if ($type === AuditRecordType::TwoFaAuthenticationActivated || $type === AuditRecordType::TwoFaAuthenticationDeactivated) {
                continue;
            }
            $groupedTypes[$type->category()][] = $type;
        }

        // Reorder groups according to defined order
        $orderedGroupedTypes = [];
        foreach ($categoryOrder as $category) {
            if (isset($groupedTypes[$category])) {
                $orderedGroupedTypes[$category] = $groupedTypes[$category];
            }
        }

        return $this->render('audit_log/view_audit_logs.html.twig', [
            'auditLogDisplays' => $displayFactory->build($auditLogs),
            'auditLogPaginator' => $auditLogs,
            'groupedTypes' => $orderedGroupedTypes,
            'selectedFilters' => $selectedFilters,
            'dateTimeFromFilter' => $dateTimeFromFilter,
            'dateTimeToFilter' => $dateTimeToFilter,
        ]);
    }
}
