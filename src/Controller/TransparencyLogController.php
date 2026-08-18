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
use App\Audit\TransparencyLogType;
use App\Entity\PackageTransparencyLogRepository;
use App\QueryFilter\QueryFilterInterface;
use App\QueryFilter\TransparencyLog\DateTimeFromFilter;
use App\QueryFilter\TransparencyLog\DateTimeToFilter;
use App\QueryFilter\TransparencyLog\PackageNameFilter;
use App\QueryFilter\TransparencyLog\TransparencyLogTypeFilter;
use App\QueryFilter\TransparencyLog\UserFilter;
use App\QueryFilter\TransparencyLog\VendorFilter;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

class TransparencyLogController extends Controller
{
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/transparency-log', name: 'view_transparency_log', methods: ['GET'])]
    public function viewTransparencyLog(Request $request, PackageTransparencyLogRepository $repository, TransparencyLogDisplayFactory $displayFactory): Response
    {
        $dateTimeFromFilter = DateTimeFromFilter::fromQuery($request->query);
        $dateTimeToFilter = DateTimeToFilter::fromQuery($request->query);

        /** @var QueryFilterInterface[] $filters */
        $filters = [
            TransparencyLogTypeFilter::fromQuery($request->query),
            UserFilter::fromQuery($request->query),
            VendorFilter::fromQuery($request->query),
            PackageNameFilter::fromQuery($request->query),
            $dateTimeFromFilter,
            $dateTimeToFilter,
        ];

        $qb = $repository->getQueryBuilderForPublicView();

        $selectedFilters = [];
        foreach ($filters as $filter) {
            $filter->filter($qb);
            $selectedFilters[$filter->getKey()] = $filter->getSelectedValue();
        }

        $paginator = new Pagerfanta(new QueryAdapter($qb, false, false));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(20);
        $paginator->setCurrentPage(max(1, $request->query->getInt('page', 1)));

        return $this->render('log/transparency_log.html.twig', [
            'transparencyLogDisplays' => $displayFactory->build($paginator),
            'paginator' => $paginator,
            'selectableTypes' => $this->selectableTypes(),
            'selectedFilters' => $selectedFilters,
            'dateTimeFromFilter' => $dateTimeFromFilter,
            'dateTimeToFilter' => $dateTimeToFilter,
        ]);
    }

    /**
     * Types offered in the filter, minus the temporarily hidden ones so the form can't ask for rows the
     * read query excludes anyway.
     *
     * @return list<TransparencyLogType>
     */
    private function selectableTypes(): array
    {
        $hidden = TransparencyLogType::temporarilyHiddenTypes();

        return array_values(array_filter(
            TransparencyLogType::cases(),
            static fn (TransparencyLogType $type): bool => !\in_array($type, $hidden, true),
        ));
    }
}
