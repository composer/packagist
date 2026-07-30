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

use App\Audit\Display\AuditLogDisplayFactory;
use App\Entity\AuditRecordRepository;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

class AdminController extends Controller
{
    /**
     * Roles that grant access to the /admin/ section. Any one of them is sufficient, so the
     * fine-grained admins (filter-list, disable-packages, disable-users, orgs, auditor) can reach the
     * section too. ROLE_ADMIN is covered transitively but listed for clarity. Also used by
     * App\Menu\MenuBuilder to decide whether to show the Admin menu entries.
     *
     * @var list<string>
     */
    public const ADMIN_ROLES = ['ROLE_ADMIN', 'ROLE_FILTER_LIST_ADMIN', 'ROLE_DISABLE_PACKAGES', 'ROLE_DISABLE_USERS', 'ROLE_ADMIN_ORGS', 'ROLE_AUDITOR'];

    #[Route(path: '/admin/', name: 'admin_index', methods: ['GET'])]
    public function index(AuditRecordRepository $auditRecordRepository, AuditLogDisplayFactory $displayFactory): Response
    {
        $this->denyAccessUnlessAdmin();

        return $this->render('admin/index.html.twig', [
            'recentModerationActivity' => $displayFactory->build($auditRecordRepository->getRecentAdminModeration(20)),
        ]);
    }

    private function denyAccessUnlessAdmin(): void
    {
        foreach (self::ADMIN_ROLES as $role) {
            if ($this->isGranted($role)) {
                return;
            }
        }

        throw $this->createAccessDeniedException();
    }
}
