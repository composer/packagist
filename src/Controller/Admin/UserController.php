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
use App\Entity\User;
use App\Entity\UserFreezeReason;
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
        $search = trim($req->query->getString('search', ''));
        $frozenFilter = $req->query->getString('frozen', '');
        $twoFactorFilter = $req->query->getString('twofa', '');
        $githubId = trim($req->query->getString('github_id', ''));
        $githubLinkedFilter = $req->query->getString('github_linked', '');
        $registeredFrom = $this->parseFilterDate($req->query->getString('registered_from', ''), false);
        $registeredTo = $this->parseFilterDate($req->query->getString('registered_to', ''), true);

        $qb = $this->getEM()->getRepository(User::class)->getUsersQueryBuilder(
            $search !== '' ? $search : null,
            $frozenFilter !== '' ? $frozenFilter : null,
            $registeredFrom,
            $registeredTo,
            $twoFactorFilter !== '' ? $twoFactorFilter : null,
            $githubId !== '' ? $githubId : null,
            $githubLinkedFilter !== '' ? $githubLinkedFilter : null,
        );

        $users = new Pagerfanta(new QueryAdapter($qb, false));
        $users->setNormalizeOutOfRangePages(true);
        $users->setMaxPerPage(30);
        $users->setCurrentPage(max(1, $req->query->getInt('page', 1)));

        return $this->render('admin/users.html.twig', [
            'users' => $users,
            'search' => $search,
            'selectedFrozenFilter' => $frozenFilter,
            'selectedTwoFactorFilter' => $twoFactorFilter,
            'selectedGithubLinkedFilter' => $githubLinkedFilter,
            'githubId' => $githubId,
            'registeredFrom' => $registeredFrom?->format('Y-m-d') ?? '',
            'registeredTo' => $registeredTo?->format('Y-m-d') ?? '',
            'freezeReasons' => UserFreezeReason::cases(),
        ]);
    }

    /**
     * Snap a `Y-m-d` filter value to the start or end of that day so both range bounds are inclusive.
     */
    private function parseFilterDate(string $value, bool $endOfDay): ?\DateTimeImmutable
    {
        if ($value === '') {
            return null;
        }

        $date = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        if ($date === false) {
            return null;
        }

        return $endOfDay ? $date->setTime(23, 59, 59) : $date;
    }
}
