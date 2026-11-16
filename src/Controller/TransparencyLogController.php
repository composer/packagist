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

use App\Log\Display\TransparencyLogDisplayFactory;
use App\Log\TransparencyLogEventType;
use App\Entity\PackageTransparencyLogRepository;
use App\QueryFilter\QueryFilterInterface;
use App\QueryFilter\TransparencyLog\DateTimeFromFilter;
use App\QueryFilter\TransparencyLog\DateTimeToFilter;
use App\QueryFilter\TransparencyLog\PackageNameFilter;
use App\QueryFilter\TransparencyLog\EventTypeFilter;
use App\QueryFilter\TransparencyLog\UserFilter;
use App\QueryFilter\TransparencyLog\VendorFilter;
use BabDev\PagerfantaBundle\Position\PositionResolver;
use Pagerfanta\CursorPagerfanta;
use Pagerfanta\Doctrine\ORM\CursorQueryAdapter;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TransparencyLogController extends Controller
{
    private const PER_PAGE = 20;

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/transparency-log', name: 'view_transparency_log', methods: ['GET'])]
    public function viewTransparencyLog(Request $request, PackageTransparencyLogRepository $repository, TransparencyLogDisplayFactory $displayFactory, PositionResolver $positionResolver): Response
    {
        // Auditors see everything; the temporarily-hidden types stay hidden for everyone else.
        $includeHiddenTypes = $this->isGranted('ROLE_AUDITOR');

        $dateTimeFromFilter = DateTimeFromFilter::fromQuery($request->query);
        $dateTimeToFilter = DateTimeToFilter::fromQuery($request->query);

        /** @var QueryFilterInterface[] $filters */
        $filters = [
            EventTypeFilter::fromQuery($request->query, $includeHiddenTypes),
            UserFilter::fromQuery($request->query),
            VendorFilter::fromQuery($request->query),
            PackageNameFilter::fromQuery($request->query),
            $dateTimeFromFilter,
            $dateTimeToFilter,
        ];

        $qb = $repository->getQueryBuilderForPublicView($includeHiddenTypes);

        $selectedFilters = [];
        foreach ($filters as $filter) {
            $filter->filter($qb);
            $selectedFilters[$filter->getKey()] = $filter->getSelectedValue();
        }

        // Keyset pagination, as counting or offsetting through millions of rows costs seconds.
        // The bundle signs cursors and turns invalid ones into a 400.
        $paginator = new CursorPagerfanta(
            new CursorQueryAdapter($qb, fetchJoinCollection: false, useOutputWalkers: false),
            self::PER_PAGE,
            $positionResolver->resolveCursorPosition($request),
        );

        return $this->render('log/transparency_log.html.twig', [
            'transparencyLogDisplays' => $displayFactory->build($paginator),
            'paginator' => $paginator,
            'selectableTypes' => $this->selectableTypes($includeHiddenTypes),
            'selectedFilters' => $selectedFilters,
            'dateTimeFromFilter' => $dateTimeFromFilter,
            'dateTimeToFilter' => $dateTimeToFilter,
        ]);
    }

    /**
     * @return list<TransparencyLogEventType>
     */
    private function selectableTypes(bool $includeHiddenTypes): array
    {
        if ($includeHiddenTypes) {
            return TransparencyLogEventType::cases();
        }

        $hidden = TransparencyLogEventType::temporarilyHiddenTypes();

        return array_values(array_filter(
            TransparencyLogEventType::cases(),
            static fn (TransparencyLogEventType $type): bool => !\in_array($type, $hidden, true),
        ));
    }
}
