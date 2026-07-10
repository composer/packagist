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

use App\Audit\Display\AuditLogDisplayFactory;
use App\Controller\Controller;
use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\FilterListEntry;
use App\Entity\PackageRepository;
use App\Entity\User;
use App\FilterList\FilterListEntryUpdateListener;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\Form\Model\FilterListEntryRequest;
use App\Form\Type\FilterListEntryType;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_FILTER_LIST_ADMIN')]
class FilterListController extends Controller
{
    public function __construct(
        private readonly FilterListEntryUpdateListener $filterListEntryUpdateListener,
        private readonly PackageRepository $packageRepo,
    ) {
    }

    #[Route(path: '/admin/filter-lists/', name: 'admin_filter_lists', methods: ['GET'])]
    public function index(Request $request): Response
    {
        $packageQuery = trim($request->query->getString('q'));
        $listFilter = $request->query->get('list');
        $sourceFilter = $request->query->get('source');
        $stateFilter = $request->query->get('state');

        $qb = $this->getEM()->getRepository(FilterListEntry::class)
            ->createQueryBuilder('fl')
            ->orderBy('fl.updatedAt', 'DESC')
            ->addOrderBy('fl.id', 'DESC');

        if ($packageQuery !== '') {
            $qb->andWhere('fl.packageName LIKE :package')
                ->setParameter('package', '%'.$packageQuery.'%');
        }

        if (\is_string($listFilter) && $listFilter !== '') {
            $list = FilterLists::tryFrom($listFilter);
            if ($list === null) {
                throw new BadRequestHttpException('Unknown list');
            }
            $qb->andWhere('fl.list = :list')->setParameter('list', $list);
        }

        if (\is_string($sourceFilter) && $sourceFilter !== '') {
            $source = FilterSources::tryFrom($sourceFilter);
            if ($source === null) {
                throw new BadRequestHttpException('Unknown source');
            }
            $qb->andWhere('fl.source = :source')->setParameter('source', $source);
        }

        if ($stateFilter === 'disabled') {
            $qb->andWhere('fl.disabled = true');
        } elseif ($stateFilter === 'enabled') {
            $qb->andWhere('fl.disabled = false');
        }

        $paginator = new Pagerfanta(new QueryAdapter($qb, false, false));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(50);
        $paginator->setCurrentPage(max(1, $request->query->getInt('page', 1)));

        return $this->render('admin/filter_list/index.html.twig', [
            'paginator' => $paginator,
            'lists' => FilterLists::cases(),
            'sources' => FilterSources::cases(),
            'filters' => [
                'q' => $packageQuery,
                'list' => $listFilter,
                'source' => $sourceFilter,
                'state' => $stateFilter,
            ],
            'csrfTokenId' => 'admin_filter_list',
        ]);
    }

    #[Route(path: '/admin/filter-lists/new', name: 'admin_filter_list_new', methods: ['GET', 'POST'])]
    public function new(Request $request): Response
    {
        $data = new FilterListEntryRequest();

        $form = $this->createForm(FilterListEntryType::class, $data, ['manual' => true, 'creating' => true]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $entry = FilterListEntry::createManual($data);

            $em = $this->getEM();
            $em->persist($entry);
            $em->flush();

            $this->filterListEntryUpdateListener->flushChangesToPackages();
            $this->addFlash('success', 'Filter list entry created.');

            return $this->redirectToRoute('admin_filter_lists');
        }

        return $this->render('admin/filter_list/new.html.twig', [
            'form' => $form->createView(),
        ]);
    }

    #[Route(path: '/admin/filter-lists/{publicId}/edit', name: 'admin_filter_list_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, string $publicId, AuditRecordRepository $auditRecordRepository, AuditLogDisplayFactory $auditLogDisplayFactory, #[CurrentUser] User $user): Response
    {
        $entry = $this->findEntry($publicId);
        $isManual = $entry->isManual();

        $data = FilterListEntryRequest::createFromEntry($entry);

        $form = $this->createForm(FilterListEntryType::class, $data, ['manual' => $isManual]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $em = $this->getEM();
            $previous = AuditRecord::getFilterListEntryData($entry);

            if ($isManual) {
                $list = $data->list ?? throw new BadRequestHttpException('List is required');
                $entry->updateManualEntry($list, $data->version, $data->reason, $data->link, $data->internalNote);
            } else {
                $entry->updateAttributes($data->version, $data->internalNote);
            }

            if (AuditRecord::getFilterListEntryData($entry) !== $previous) {
                $em->persist(AuditRecord::filterListEntryEdited($entry, $previous, $user, $this->packageRepo->getPackageIdByName($entry->getPackageName())));
                $em->flush();

                $this->filterListEntryUpdateListener->flushChangesToPackages();
                $this->addFlash('success', 'Filter list entry updated.');
            } else {
                $this->addFlash('info', 'No changes to save.');
            }

            return $this->redirectToRoute('admin_filter_lists');
        }

        $auditLogDisplays = $auditLogDisplayFactory->build($auditRecordRepository->findForFilterListEntry($publicId));

        return $this->render('admin/filter_list/edit.html.twig', [
            'form' => $form->createView(),
            'entry' => $entry,
            'auditLogDisplays' => $auditLogDisplays,
        ]);
    }

    #[Route(path: '/admin/filter-lists/{publicId}/disable', name: 'admin_filter_list_disable', methods: ['POST'])]
    public function disable(Request $request, string $publicId, #[CurrentUser] User $user): RedirectResponse
    {
        $this->assertCsrf($request);

        $entry = $this->findEntry($publicId);
        if ($entry->isDisabled()) {
            $this->addFlash('warning', 'Entry is already disabled.');

            return $this->redirectToRoute('admin_filter_lists');
        }

        $entry->disable();

        $em = $this->getEM();
        $em->persist(AuditRecord::filterListEntryDisabled($entry, $user, $this->packageRepo->getPackageIdByName($entry->getPackageName())));
        $em->flush();

        $this->filterListEntryUpdateListener->flushChangesToPackages();
        $this->addFlash('success', 'Filter list entry disabled.');

        return $this->redirectToRoute('admin_filter_lists');
    }

    #[Route(path: '/admin/filter-lists/{publicId}/enable', name: 'admin_filter_list_enable', methods: ['POST'])]
    public function enable(Request $request, string $publicId, #[CurrentUser] User $user): RedirectResponse
    {
        $this->assertCsrf($request);

        $entry = $this->findEntry($publicId);
        if (!$entry->isDisabled()) {
            $this->addFlash('warning', 'Entry is already enabled.');

            return $this->redirectToRoute('admin_filter_lists');
        }

        $entry->enable();

        $em = $this->getEM();
        $em->persist(AuditRecord::filterListEntryEnabled($entry, $user, $this->packageRepo->getPackageIdByName($entry->getPackageName())));
        $em->flush();

        $this->filterListEntryUpdateListener->flushChangesToPackages();
        $this->addFlash('success', 'Filter list entry re-enabled.');

        return $this->redirectToRoute('admin_filter_lists');
    }

    #[Route(path: '/admin/filter-lists/bulk', name: 'admin_filter_list_bulk', methods: ['POST'])]
    public function bulk(Request $request, #[CurrentUser] User $user): RedirectResponse
    {
        $this->assertCsrf($request);

        $action = $request->request->getString('action');
        if (!\in_array($action, ['enable', 'disable'], true)) {
            throw new BadRequestHttpException('Unknown bulk action');
        }

        $publicIds = array_values(array_filter(
            $request->request->all('publicIds'),
            static fn (mixed $id): bool => \is_string($id) && $id !== '',
        ));
        if ($publicIds === []) {
            $this->addFlash('warning', 'No entries selected.');

            return $this->redirectToRoute('admin_filter_lists');
        }

        $em = $this->getEM();
        $entries = $em->getRepository(FilterListEntry::class)->findBy(['publicId' => $publicIds]);

        $changed = 0;
        foreach ($entries as $entry) {
            if ($action === 'disable') {
                if ($entry->isDisabled()) {
                    continue;
                }
                $entry->disable();
                $em->persist(AuditRecord::filterListEntryDisabled($entry, $user, $this->packageRepo->getPackageIdByName($entry->getPackageName())));
                $changed++;
            } else {
                if (!$entry->isDisabled()) {
                    continue;
                }
                $entry->enable();
                $em->persist(AuditRecord::filterListEntryEnabled($entry, $user, $this->packageRepo->getPackageIdByName($entry->getPackageName())));
                $changed++;
            }
        }

        if ($changed > 0) {
            $em->flush();
            $this->filterListEntryUpdateListener->flushChangesToPackages();
        }

        $this->addFlash('success', sprintf(
            '%d %s %s.',
            $changed,
            $changed === 1 ? 'entry' : 'entries',
            $action === 'disable' ? 'disabled' : 're-enabled',
        ));

        return $this->redirectToRoute('admin_filter_lists');
    }

    private function findEntry(string $publicId): FilterListEntry
    {
        $entry = $this->getEM()->getRepository(FilterListEntry::class)->findOneByPublicId($publicId);
        if ($entry === null) {
            throw new NotFoundHttpException('Filter list entry not found');
        }

        return $entry;
    }

    private function assertCsrf(Request $request): void
    {
        if (!$this->isCsrfTokenValid('admin_filter_list', $request->request->getString('token'))) {
            throw new BadRequestHttpException('Invalid CSRF token');
        }
    }
}
