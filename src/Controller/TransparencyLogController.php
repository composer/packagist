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
        /** @var QueryFilterInterface[] $filters */
        $filters = [
            AuditRecordTypeFilter::fromQuery($request->query),
            ActorFilter::fromQuery($request->query),
        ];

        $qb = $auditRecordRepository->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC');

        foreach ($filters as $filter) {
            $filter->filter($qb);
        }

        $auditLogs = new Pagerfanta(new QueryAdapter($qb, true));
        $auditLogs->setNormalizeOutOfRangePages(true);
        $auditLogs->setMaxPerPage(20);
        $auditLogs->setCurrentPage(max(1, $request->query->getInt('page', 1)));

        $selectedFilters = [];
        foreach ($filters as $filter) {
            $selectedFilters[$filter->getKey()] = $filter->getSelectedValue();
        }

        return $this->render('audit_log/view_audit_logs.html.twig', [
            'auditLogDisplays' => $displayFactory->build($auditLogs),
            'auditLogPaginator' => $auditLogs,
            'allTypes' => AuditRecordType::cases(),
            'selectedFilters' => $selectedFilters,
        ]);
    }
}
