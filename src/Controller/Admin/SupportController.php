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
use App\Entity\Package;
use App\Entity\SupportRequest;
use App\Entity\SupportRequestMessage;
use App\Entity\SupportRequestRepository;
use App\Entity\User;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use App\Security\TwoFactorAuthManager;
use App\Support\Attributes\LostTwoFactorAttributes;
use App\Support\SupportNotifier;
use App\Support\SupportQueueAccess;
use App\Support\SupportRequestStatus;
use App\Support\SupportRequestType;
use App\Support\SupportRiskAssessor;
use Pagerfanta\Doctrine\ORM\QueryAdapter;
use Pagerfanta\Pagerfanta;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

/**
 * The support task queue.
 *
 * Access is per request type rather than per controller, so a fine-grained moderator sees only the
 * kinds of request they can actually act on. {@see SupportQueueAccess} is the single place that
 * decides, and the admin menu asks it the same question.
 */
class SupportController extends Controller
{
    private const string CSRF_TOKEN_ID = 'admin_support';

    public function __construct(
        private readonly SupportRequestRepository $supportRequests,
        private readonly SupportQueueAccess $access,
        private readonly SupportNotifier $notifier,
    ) {
    }

    #[Route(path: '/admin/support/', name: 'admin_support_requests', methods: ['GET'])]
    public function index(Request $req): Response
    {
        $visibleTypes = $this->visibleTypes();

        // No status in the query means the default view (open only); an explicitly empty one means
        // "any status", which is how the filter dropdown clears itself.
        $statusFilter = $req->query->has('status') ? $req->query->getString('status') : SupportRequestStatus::Open->value;
        $status = $statusFilter === '' ? null : (SupportRequestStatus::tryFrom($statusFilter) ?? throw new BadRequestHttpException('Unknown status'));

        $type = $this->enumFromQuery($req, 'type', SupportRequestType::class);
        if ($type !== null && !$this->access->canSee($type)) {
            throw $this->createAccessDeniedException();
        }

        $search = trim($req->query->getString('q'));

        $qb = $this->supportRequests->createQueueQueryBuilder($visibleTypes, $status, $type, $search);

        /** @var Pagerfanta<SupportRequest> $paginator */
        $paginator = new Pagerfanta(new QueryAdapter($qb, false, false));
        $paginator->setNormalizeOutOfRangePages(true);
        $paginator->setMaxPerPage(50);
        $paginator->setCurrentPage(max(1, $req->query->getInt('page', 1)));

        $rows = array_values(iterator_to_array($paginator));
        $now = new \DateTimeImmutable();

        return $this->render('admin/support/index.html.twig', [
            'paginator' => $paginator,
            'noteCounts' => $this->supportRequests->countMessagesFor($rows),
            // Resolved here rather than in Twig, whose date() hands back a DateTime, so the queue
            // and the detail page agree on what is still held.
            'heldIds' => array_values(array_map(
                static fn (SupportRequest $r): string => $r->publicId,
                array_filter($rows, static fn (SupportRequest $r): bool => $r->isOpen() && !$r->isApprovable($now)),
            )),
            'visibleTypes' => $visibleTypes,
            'statuses' => SupportRequestStatus::cases(),
            'filters' => [
                'status' => $statusFilter,
                'type' => $type?->value,
                'q' => $search,
            ],
        ]);
    }

    #[Route(path: '/admin/support/{publicId}', name: 'admin_support_request', methods: ['GET'])]
    public function show(SupportRiskAssessor $riskAssessor, AuditLogDisplayFactory $displayFactory, FavoriteManager $favMgr, DownloadManager $dlMgr, string $publicId): Response
    {
        $request = $this->findRequest($publicId);

        $context = [
            'supportRequest' => $request,
            'csrfTokenId' => self::CSRF_TOKEN_ID,
            'suggestedReply' => $this->hasReply($request) ? null : $request->type->suggestedReply($request),
        ];

        if ($request->type === SupportRequestType::PackageTransfer) {
            $context['resolvedPackages'] = $this->resolvePackages($request, $favMgr, $dlMgr);
        }

        if ($request->type === SupportRequestType::LostTwoFactor) {
            $profile = $riskAssessor->assess($request->user);
            $context['risk'] = $profile;
            $context['riskAuditDisplays'] = $displayFactory->build($profile->recentSecurityEvents);
            $context['previousRequests'] = $this->supportRequests->findPreviousTwoFactorRequests($request);
            // Resolved here rather than in Twig, whose date() hands back a DateTime.
            $context['coolingOffElapsed'] = $request->isApprovable(new \DateTimeImmutable());
        }

        if ($request->type === SupportRequestType::AccountDeletion) {
            $context['requesterPackages'] = $this->resolveOwnPackages($request->user, $favMgr, $dlMgr);
        }

        return $this->render('admin/support/show.html.twig', $context);
    }

    #[Route(path: '/admin/support/{publicId}/note', name: 'admin_support_request_note', methods: ['POST'])]
    public function addNote(Request $req, string $publicId, #[CurrentUser] User $actor): RedirectResponse
    {
        $this->assertCsrf($req);
        $request = $this->findRequest($publicId);

        $contents = trim($req->request->getString('contents'));
        if ($contents === '') {
            $this->addFlash('warning', 'The note was empty, nothing was saved.');

            return $this->redirectToRoute('admin_support_request', ['publicId' => $publicId]);
        }

        $note = SupportRequestMessage::internalNote($request, $contents, $actor);
        $request->touch(new \DateTimeImmutable());

        $em = $this->getEM();
        $em->persist($note);
        $em->flush();

        $this->addFlash('success', 'Note added.');

        return $this->redirectToRoute('admin_support_request', ['publicId' => $publicId]);
    }

    #[Route(path: '/admin/support/{publicId}/reply', name: 'admin_support_request_reply', methods: ['POST'])]
    public function reply(Request $req, string $publicId, #[CurrentUser] User $actor): RedirectResponse
    {
        $this->assertCsrf($req);
        $request = $this->findRequest($publicId);

        $contents = trim($req->request->getString('contents'));
        if ($contents === '') {
            $this->addFlash('warning', 'The reply was empty, nothing was sent.');

            return $this->redirectToRoute('admin_support_request', ['publicId' => $publicId]);
        }

        $reply = SupportRequestMessage::reply($request, $contents, $actor);
        $request->touch(new \DateTimeImmutable());

        $em = $this->getEM();
        $em->persist($reply);
        $em->flush();

        $this->notifier->notifyUserOfReply($request, $reply);

        $this->addFlash('success', 'Reply sent to '.$request->user->getEmail().'.');

        return $this->redirectToRoute('admin_support_request', ['publicId' => $publicId]);
    }

    #[Route(path: '/admin/support/{publicId}/status', name: 'admin_support_request_status', methods: ['POST'])]
    public function changeStatus(Request $req, string $publicId, #[CurrentUser] User $actor): RedirectResponse
    {
        $this->assertCsrf($req);
        $request = $this->findRequest($publicId);

        $status = SupportRequestStatus::tryFrom($req->request->getString('status'));
        if ($status === null) {
            throw new BadRequestHttpException('Unknown status');
        }

        $now = new \DateTimeImmutable();
        match ($status) {
            SupportRequestStatus::Open => $request->reopen($now),
            SupportRequestStatus::Resolved => $request->resolve($now),
            SupportRequestStatus::Closed => $request->close($now),
        };

        $em = $this->getEM();
        // Recorded on the thread so the status carries an actor, the way notes and replies do.
        $em->persist(SupportRequestMessage::internalNote($request, 'Marked as '.$status->label().'.', $actor));
        $em->flush();
        $this->addFlash('success', 'Request marked as '.$status->label().'.');

        return $this->redirectToRoute('admin_support_request', ['publicId' => $publicId]);
    }

    /**
     * Disables two-factor authentication, tells the requester it was granted, and closes the task in
     * one POST.
     */
    #[Route(path: '/admin/support/{publicId}/grant-2fa-reset', name: 'admin_support_request_grant_2fa_reset', methods: ['POST'])]
    public function grantTwoFactorReset(Request $req, string $publicId, #[CurrentUser] User $actor, TwoFactorAuthManager $authManager): RedirectResponse
    {
        // Seeing the queue is not enough; this is the capability that actually hands out access.
        // Checked before the token so a missing role reports as denied rather than as a bad token.
        $this->denyAccessUnlessGranted('ROLE_DISABLE_2FA');
        $this->assertCsrf($req);

        $request = $this->findRequest($publicId);
        $redirect = $this->redirectToRoute('admin_support_request', ['publicId' => $publicId]);

        if ($request->type !== SupportRequestType::LostTwoFactor) {
            throw new BadRequestHttpException('Not a two-factor reset request');
        }

        if (!$request->isOpen()) {
            $this->addFlash('warning', 'This request has already been dealt with.');

            return $redirect;
        }

        $now = new \DateTimeImmutable();
        if (!$request->isApprovable($now)) {
            $this->addFlash('warning', 'This request is held until '.$request->attributesOf(LostTwoFactorAttributes::class)->approvableAt?->format('Y-m-d H:i').' UTC so the account owner has time to object.');

            return $redirect;
        }

        // Re-checked here, not just at request time: freezing an account between the request and the
        // approval must not leave a path that hands a frozen bad actor a working login.
        if ($request->user->isFrozen()) {
            $this->addFlash('error', 'This account is frozen, so its two-factor authentication cannot be reset.');

            return $redirect;
        }

        if (!$request->user->isTotpAuthenticationEnabled()) {
            $request->resolve($now);
            $this->getEM()->flush();
            $this->addFlash('info', 'Two-factor authentication was already off on this account; the request has been resolved.');

            return $redirect;
        }

        // Built before the call because disableTwoFactorAuth() flushes, which persists these too.
        $reply = SupportRequestMessage::reply($request, SupportRequestType::twoFactorGrantedReply($request), $actor);
        $request->resolve($now);
        $this->getEM()->persist($reply);

        $authManager->disableTwoFactorAuth(
            $request->user,
            $actor,
            'Reset by support, request '.$request->publicId,
            supportReset: true,
        );

        $this->notifier->notifyUserOfReply($request, $reply);

        $this->addFlash('success', 'Two-factor authentication disabled and request '.$request->publicId.' resolved.');

        return $redirect;
    }

    /**
     * @return list<SupportRequestType>
     */
    private function visibleTypes(): array
    {
        $types = $this->access->visibleTypes();
        if ($types === []) {
            throw $this->createAccessDeniedException();
        }

        return $types;
    }

    /**
     * Denies anyone who can action no type at all, before we go looking for the request. Per-type
     * access is then checked against the one we found.
     */
    private function assertHasQueueAccess(): void
    {
        $this->visibleTypes();
    }

    private function findRequest(string $publicId): SupportRequest
    {
        $this->assertHasQueueAccess();

        $request = $this->supportRequests->findOneByPublicId($publicId);
        if ($request === null) {
            throw new NotFoundHttpException('Support request not found');
        }

        if (!$this->access->canSee($request->type)) {
            throw $this->createAccessDeniedException();
        }

        return $request;
    }

    private function hasReply(SupportRequest $request): bool
    {
        foreach ($request->messages as $message) {
            if (!$message->internal) {
                return true;
            }
        }

        return false;
    }

    /**
     * Resolves the names the requester typed against real packages, so the admin can judge the claim
     * against the authoritative maintainer list rather than against a string somebody typed.
     *
     * @return list<array{name: string, package: Package|null, downloads: int}>
     */
    private function resolvePackages(SupportRequest $request, FavoriteManager $favMgr, DownloadManager $dlMgr): array
    {
        $names = $request->packageNameList();
        if ($names === []) {
            return [];
        }

        /** @var list<Package> $found */
        $found = $this->getEM()->getRepository(Package::class)->createQueryBuilder('p')
            ->addSelect('m')
            ->leftJoin('p.maintainers', 'm')
            ->where('p.name IN (:names)')
            ->setParameter('names', $names)
            ->getQuery()
            ->getResult();

        $byName = [];
        foreach ($found as $package) {
            $byName[$package->getName()] = $package;
        }

        $metadata = $this->getPackagesMetadata($favMgr, $dlMgr, $found);

        $resolved = [];
        foreach ($names as $name) {
            $package = $byName[$name] ?? null;
            $resolved[] = [
                'name' => $name,
                'package' => $package,
                'downloads' => $package !== null ? ($metadata['downloads'][$package->getId()] ?? 0) : 0,
            ];
        }

        return $resolved;
    }

    /**
     * @return list<array{package: Package, downloads: int, maintainers: int}>
     */
    private function resolveOwnPackages(User $user, FavoriteManager $favMgr, DownloadManager $dlMgr): array
    {
        $packages = $user->getPackages()->toArray();
        $metadata = $this->getPackagesMetadata($favMgr, $dlMgr, $packages);

        $resolved = [];
        foreach ($packages as $package) {
            $resolved[] = [
                'package' => $package,
                'downloads' => $metadata['downloads'][$package->getId()] ?? 0,
                'maintainers' => $package->getMaintainers()->count(),
            ];
        }

        return $resolved;
    }

    /**
     * @template T of \BackedEnum
     *
     * @param class-string<T> $enum
     *
     * @return T|null
     */
    private function enumFromQuery(Request $req, string $key, string $enum): ?\BackedEnum
    {
        $value = $req->query->getString($key);
        if ($value === '') {
            return null;
        }

        return $enum::tryFrom($value) ?? throw new BadRequestHttpException('Unknown '.$key);
    }

    private function assertCsrf(Request $req): void
    {
        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $req->request->getString('token'))) {
            throw new BadRequestHttpException('Invalid CSRF token');
        }
    }
}
