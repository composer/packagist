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
use App\Entity\AuditRecord;
use App\Entity\AuditRecordRepository;
use App\Entity\FilterListEntry;
use App\Entity\FilterListEntryRepository;
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
use Symfony\Component\Security\Http\Attribute\IsGranted;

#[IsGranted('ROLE_FILTER_LIST_ADMIN')]
class AdminFilterListController extends Controller
{
    public function __construct(
        private readonly FilterListEntryUpdateListener $filterListEntryUpdateListener,
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

    #[Route(path: '/admin/filter-lists/{publicId}/edit', name: 'admin_filter_list_edit', methods: ['GET', 'POST'])]
    public function edit(Request $request, string $publicId, AuditRecordRepository $auditRecordRepository, AuditLogDisplayFactory $auditLogDisplayFactory): Response
    {
        $entry = $this->findEntry($publicId);

        $data = FilterListEntryRequest::createFromEntry($entry);

        $form = $this->createForm(FilterListEntryType::class, $data);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $previousVersion = $entry->getVersion();

            $entry->updateAttributes($data->version);

            $em = $this->getEM();
            $em->persist(AuditRecord::filterListEntryEdited($entry, $previousVersion, $this->getActor()));
            $em->flush();

            $this->filterListEntryUpdateListener->flushChangesToPackages();
            $this->addFlash('success', 'Filter list entry updated.');

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
    public function disable(Request $request, string $publicId): RedirectResponse
    {
        $this->assertCsrf($request);

        $entry = $this->findEntry($publicId);
        if ($entry->isDisabled()) {
            $this->addFlash('warning', 'Entry is already disabled.');

            return $this->redirectToRoute('admin_filter_lists');
        }

        $entry->disable();

        $em = $this->getEM();
        $em->persist(AuditRecord::filterListEntryDisabled($entry, $this->getActor()));
        $em->flush();

        $this->filterListEntryUpdateListener->flushChangesToPackages();
        $this->addFlash('success', 'Filter list entry disabled.');

        return $this->redirectToRoute('admin_filter_lists');
    }

    #[Route(path: '/admin/filter-lists/{publicId}/enable', name: 'admin_filter_list_enable', methods: ['POST'])]
    public function enable(Request $request, string $publicId): RedirectResponse
    {
        $this->assertCsrf($request);

        $entry = $this->findEntry($publicId);
        if (!$entry->isDisabled()) {
            $this->addFlash('warning', 'Entry is already enabled.');

            return $this->redirectToRoute('admin_filter_lists');
        }

        $entry->enable();

        $em = $this->getEM();
        $em->persist(AuditRecord::filterListEntryEnabled($entry, $this->getActor()));
        $em->flush();

        $this->filterListEntryUpdateListener->flushChangesToPackages();
        $this->addFlash('success', 'Filter list entry re-enabled.');

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

    private function getActor(): ?User
    {
        $user = $this->getUser();

        return $user instanceof User ? $user : null;
    }
}
