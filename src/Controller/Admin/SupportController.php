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

use App\Log\Display\AuditLogDisplayFactory;
use App\Controller\Controller;
use App\Entity\Package;
use App\Entity\SupportRequest;
use App\Entity\SupportRequestMessage;
use App\Entity\SupportRequestRepository;
use App\Entity\User;
use App\Model\DownloadManager;
use App\Model\FavoriteManager;
use App\Model\PackageManager;
use App\Security\TwoFactorAuthManager;
use App\Security\Voter\PackageActions;
use App\Support\Attributes\LostTwoFactorAttributes;
use App\Support\Attributes\PackageTransferAttributes;
use App\Support\Attributes\VendorClaimAttributes;
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

    /**
     * Enough of a vendor namespace to judge a claim against. A vendor with more packages than this is
     * already answer enough -- it is plainly in use -- and the maintainer list below is computed over
     * all of them regardless of this cap.
     */
    private const int VENDOR_PACKAGE_LIMIT = 50;

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

        // Deliberately not `q`/`type`: js/search.js reads those two off the query string on every
        // page and would either redirect this one to /search/ or overlay the package search on it.
        $type = $this->enumFromQuery($req, 'requestType', SupportRequestType::class);
        if ($type !== null && !$this->access->canSee($type)) {
            throw $this->createAccessDeniedException();
        }

        $search = trim($req->query->getString('search'));

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
                array_filter($rows, static function (SupportRequest $r) use ($now): bool {
                    $attributes = $r->attributes;

                    return $r->isOpen() && $attributes instanceof LostTwoFactorAttributes && !$attributes->isApprovable($now);
                }),
            )),
            'visibleTypes' => $visibleTypes,
            'statuses' => SupportRequestStatus::cases(),
            'filters' => [
                'status' => $statusFilter,
                'requestType' => $type?->value,
                'search' => $search,
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
            $context['vendors'] = $this->resolveRequestVendors($request, $favMgr, $dlMgr);
        }

        if ($request->type === SupportRequestType::LostTwoFactor) {
            $profile = $riskAssessor->assess($request->user);
            $context['risk'] = $profile;
            $context['riskAuditDisplays'] = $displayFactory->build($profile->recentSecurityEvents);
            $context['previousRequests'] = $this->supportRequests->findPreviousTwoFactorRequests($request);
            // Resolved here rather than in Twig, whose date() hands back a DateTime.
            $context['coolingOffElapsed'] = $request->attributesOf(LostTwoFactorAttributes::class)->isApprovable(new \DateTimeImmutable());
        }

        if ($request->type === SupportRequestType::VendorClaim) {
            $context['vendor'] = $this->resolveVendor($request->attributesOf(VendorClaimAttributes::class)->vendorName, $favMgr, $dlMgr);
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
     * Hands one of the requested packages to the requester, making them its sole maintainer, which is
     * what a transfer request asks for. The package page's own form stays the route for anything more
     * nuanced, such as keeping an existing maintainer on.
     */
    #[Route(path: '/admin/support/{publicId}/transfer-package', name: 'admin_support_request_transfer_package', methods: ['POST'])]
    public function transferPackage(Request $req, string $publicId, #[CurrentUser] User $actor, PackageManager $packageManager): RedirectResponse
    {
        $this->assertCsrf($req);

        $request = $this->findRequest($publicId);
        $redirect = $this->redirectToRoute('admin_support_request', ['publicId' => $publicId]);

        if ($request->type !== SupportRequestType::PackageTransfer) {
            throw new BadRequestHttpException('Not a package transfer request');
        }

        if (!$request->isOpen()) {
            $this->addFlash('warning', 'This request has already been dealt with.');

            return $redirect;
        }

        // Restricted to the names the requester actually listed. The role behind this queue can
        // transfer any package from the package page anyway, so this is not the security boundary --
        // it keeps a support action inside the request it is filed against, and off the audit trail
        // of packages nobody asked about.
        $name = $req->request->getString('package');
        if (!in_array($name, $request->attributesOf(PackageTransferAttributes::class)->packageNames, true)) {
            throw new BadRequestHttpException('Package is not part of this request');
        }

        $package = $this->getEM()->getRepository(Package::class)->findOneBy(['name' => $name]);
        if ($package === null) {
            $this->addFlash('error', $name.' no longer exists.');

            return $redirect;
        }

        // The same gate the package page's transfer form uses, asked of this admin and this package.
        $this->denyAccessUnlessGranted(PackageActions::TransferPackage->value, $package);

        if (!$packageManager->transferPackage($package, [$request->user], true)) {
            $this->addFlash('warning', $request->user->getUsername().' already maintains '.$name.' alone.');

            return $redirect;
        }

        $em = $this->getEM();
        // On the thread rather than only in audit_log, so the next admin to open the task can see
        // which of the requested packages have already been handed over.
        $em->persist(SupportRequestMessage::internalNote($request, 'Transferred '.$name.' to '.$request->user->getUsername().'.', $actor));
        $request->touch(new \DateTimeImmutable());
        $em->flush();

        $this->addFlash('success', $name.' transferred to '.$request->user->getUsername().'.');

        return $redirect;
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
        $attributes = $request->attributesOf(LostTwoFactorAttributes::class);
        if (!$attributes->isApprovable($now)) {
            $this->addFlash('warning', 'This request is held until '.$attributes->approvableAt?->format('Y-m-d H:i').' UTC so the account owner has time to object.');

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
     * @return list<array{name: string, package: Package|null, downloads: int, transferred: bool}>
     */
    private function resolvePackages(SupportRequest $request, FavoriteManager $favMgr, DownloadManager $dlMgr): array
    {
        $names = $request->attributesOf(PackageTransferAttributes::class)->packageNames;
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
                'transferred' => $package !== null && $package->isMaintainer($request->user),
            ];
        }

        return $resolved;
    }

    /**
     * Every namespace the requested packages sit in, so a transfer can be judged against the rest of
     * the vendor rather than against the listed names alone. Keyed by vendor, deduplicated, and
     * derived from the names as typed: a package that does not exist yet still tells us which
     * namespace the requester is reaching into.
     *
     * @return array<string, array{packages: list<array{package: Package, downloads: int}>, total: int, maintainers: list<User>}>
     */
    private function resolveRequestVendors(SupportRequest $request, FavoriteManager $favMgr, DownloadManager $dlMgr): array
    {
        $vendors = [];
        foreach ($request->attributesOf(PackageTransferAttributes::class)->packageNames as $name) {
            $slash = strpos($name, '/');
            if ($slash === false || $slash === 0) {
                continue;
            }

            $vendor = substr($name, 0, $slash);
            $vendors[$vendor] ??= $this->resolveVendor($vendor, $favMgr, $dlMgr);
        }

        ksort($vendors);

        return $vendors;
    }

    /**
     * What the claimed vendor namespace holds today, so the admin can tell an unused name from one
     * somebody else is actively publishing under without leaving the page.
     *
     * @return array{packages: list<array{package: Package, downloads: int}>, total: int, maintainers: list<User>}
     */
    private function resolveVendor(string $vendor, FavoriteManager $favMgr, DownloadManager $dlMgr): array
    {
        $repo = $this->getEM()->getRepository(Package::class);

        $total = (int) $repo->createQueryBuilder('p')
            ->select('COUNT(p.id)')
            ->where('p.vendor = :vendor')
            ->setParameter('vendor', $vendor)
            ->getQuery()
            ->getSingleScalarResult();

        // Names first, then the entities: a fetch-join to maintainers under setMaxResults would apply
        // the LIMIT to joined rows rather than to packages, handing back partial collections.
        /** @var list<array{name: string}> $nameRows */
        $nameRows = $repo->createQueryBuilder('p')
            ->select('p.name')
            ->where('p.vendor = :vendor')
            ->setParameter('vendor', $vendor)
            ->orderBy('p.name', 'ASC')
            ->setMaxResults(self::VENDOR_PACKAGE_LIMIT)
            ->getQuery()
            ->getResult();
        $names = array_column($nameRows, 'name');

        $packages = [];
        if ($names !== []) {
            /** @var list<Package> $packages */
            $packages = $repo->createQueryBuilder('p')
                ->addSelect('m')
                ->leftJoin('p.maintainers', 'm')
                ->where('p.name IN (:names)')
                ->setParameter('names', $names)
                ->orderBy('p.name', 'ASC')
                ->getQuery()
                ->getResult();
        }

        $metadata = $this->getPackagesMetadata($favMgr, $dlMgr, $packages);

        $rows = [];
        foreach ($packages as $package) {
            $rows[] = ['package' => $package, 'downloads' => $metadata['downloads'][$package->getId()] ?? 0];
        }

        // Over the whole vendor, not just the page above: "who holds this namespace" is the question
        // the claim turns on, and a truncated answer to it would be worse than none.
        /** @var list<User> $maintainers */
        $maintainers = $this->getEM()->createQuery(
            'SELECT DISTINCT m FROM App\Entity\User m JOIN m.packages p WHERE p.vendor = :vendor ORDER BY m.usernameCanonical ASC'
        )->setParameter('vendor', $vendor)->getResult();

        return ['packages' => $rows, 'total' => $total, 'maintainers' => $maintainers];
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
