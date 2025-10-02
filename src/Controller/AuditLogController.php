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
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class AuditLogController extends Controller
{
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/audit-log', name: 'view_audit_logs')]
    public function viewAuditLogs(Request $req, AuditRecordRepository $auditRecordRepository): Response
    {
        $query = $auditRecordRepository->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC');

        $auditLogs = new Pagerfanta(new QueryAdapter($query, false));
        $auditLogs->setNormalizeOutOfRangePages(true);
        $auditLogs->setMaxPerPage(20);
        $auditLogs->setCurrentPage(max(1, $req->query->getInt('page', 1)));

        return $this->render('audit_log/view_audit_logs.html.twig', [
            'auditLogs' => $auditLogs,
        ]);
    }
}
