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

use App\Entity\PackageRepository;
use App\Entity\SupportMessageVisibility;
use App\Entity\SupportRequest;
use App\Entity\SupportRequestMessage;
use App\Entity\SupportRequestRepository;
use App\Entity\User;
use App\Form\Model\AccountDeletionSupportRequest;
use App\Form\Model\LostTwoFactorSupportRequest;
use App\Form\Model\PackageDisposition;
use App\Form\Model\PackageTransferSupportRequest;
use App\Form\Model\VendorClaimSupportRequest;
use App\Form\Type\AccountDeletionSupportType;
use App\Form\Type\LostTwoFactorSupportType;
use App\Form\Type\PackageTransferSupportType;
use App\Form\Type\VendorClaimSupportType;
use App\Support\SupportNotifier;
use App\Support\SupportRequestRateLimiter;
use App\Support\SupportRequestStatus;
use App\Support\SupportRequestType;
use App\Support\SupportRiskAssessor;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Scheb\TwoFactorBundle\Security\Authentication\Token\TwoFactorTokenInterface;
use Symfony\Component\Form\FormInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Http\Attribute\CurrentUser;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * Public contact page and the guided request workflows behind it.
 *
 * The account a request is about always comes from the session token, never from a route parameter
 * or a form field, so it is structurally impossible to file a request against somebody else's
 * account.
 */
class SupportController extends Controller
{
    public function __construct(
        private readonly SupportRequestRepository $supportRequests,
        private readonly SupportNotifier $notifier,
        private readonly SupportRequestRateLimiter $rateLimiter,
    ) {
    }

    #[Route(path: '/contact', name: 'support_contact', methods: ['GET'])]
    public function contact(): Response
    {
        return $this->render('support/contact.html.twig');
    }

    /**
     * Lives under /2fa on purpose. The existing `^/2fa` access_control rule requires
     * IS_AUTHENTICATED_2FA_IN_PROGRESS, which only a TwoFactorToken can satisfy, so the route is
     * unreachable both logged out and fully logged in. access_control has no catch-all deny, so
     * sitting under that prefix is what gives this a second layer beyond the check below.
     */
    #[Route(path: '/2fa/lost', name: 'support_lost_2fa', methods: ['GET', 'POST'])]
    public function lostTwoFactor(Request $req, TokenStorageInterface $tokenStorage, SupportRiskAssessor $riskAssessor): Response
    {
        $user = $this->userFromTwoFactorToken($tokenStorage);

        // UserChecker rejects frozen accounts at authentication, which happens before the 2FA step,
        // so this state is not reachable through login today. Checked anyway: the whole flow hands
        // out account access, and a frozen account must never gain a recovery path.
        if ($user->isFrozen() || !$user->isTotpAuthenticationEnabled()) {
            throw $this->createAccessDeniedException('This account cannot request a two-factor reset.');
        }

        $existing = $this->supportRequests->findOpen($user, SupportRequestType::LostTwoFactor);
        if ($existing !== null) {
            return $this->renderLostTwoFactor(null, $existing);
        }

        $data = new LostTwoFactorSupportRequest();
        $form = $this->createForm(LostTwoFactorSupportType::class, $data)->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            if ($this->rateLimiter->isLimited($user, SupportRequestType::LostTwoFactor, $req->getClientIp())) {
                $this->addFlash('error', 'You have submitted this request too many times recently. Please email contact@packagist.org instead.');

                return $this->renderLostTwoFactor($form, null);
            }

            $cancelToken = bin2hex(random_bytes(32));
            $request = SupportRequest::lostTwoFactor(
                $user,
                $data->description,
                $cancelToken,
                $riskAssessor->coolingOffUntil($user, new \DateTimeImmutable()),
            );
            $request->ip = $req->getClientIp();

            $em = $this->getEM();
            $em->persist($request);

            try {
                $em->flush();
            } catch (UniqueConstraintViolationException) {
                // Lost the race against a double submit. The violation itself proves an open request
                // exists, and the failed flush has closed the EM, so report it without reading back.
                return $this->renderLostTwoFactor(null, null, duplicate: true);
            }

            $this->rateLimiter->recordSubmission($user, SupportRequestType::LostTwoFactor, $req->getClientIp());
            $this->notifier->notifyTwoFactorRequestFiled($request, $cancelToken);
            $this->notifier->notifyAdmins($request);

            return $this->renderLostTwoFactor(null, $request);
        }

        return $this->renderLostTwoFactor($form, null);
    }

    /**
     * The "this wasn't me" link from the alert mail. Public because the recipient cannot log in
     * (that is the whole problem); the token in the link is the authentication. It deliberately
     * keeps working after the request is actioned, so an owner who reads the email late can still
     * raise the alarm rather than getting a dead link.
     */
    #[Route(path: '/contact/cancel-2fa-request/{publicId}', name: 'support_cancel_2fa_request', methods: ['GET', 'POST'])]
    public function cancelTwoFactorRequest(Request $req, string $publicId): Response
    {
        // The token travels in the query on the GET and in the body on the POST, so read both.
        $token = $req->isMethod('POST') ? $req->request->getString('token') : $req->query->getString('token');

        $request = $this->supportRequests->findOneByPublicId($publicId);
        if ($request === null || $request->type !== SupportRequestType::LostTwoFactor || !$request->matchesCancelToken($token)) {
            throw new NotFoundHttpException('Unknown or expired cancellation link');
        }

        // Acting on GET would let a mail scanner (SafeLinks, Proofpoint and friends) veto a genuine
        // request and sign the owner out by prefetching the link, so GET only offers the button.
        if (!$req->isMethod('POST')) {
            return $this->render('support/cancel_confirm.html.twig', [
                'request' => $request,
                'token' => $token,
                'alreadyActioned' => !$request->isOpen(),
            ]);
        }

        $em = $this->getEM();
        $now = new \DateTimeImmutable();

        // Claimed with a conditional UPDATE rather than a read-then-write: if an admin grants the
        // reset in the same moment, losing that race would overwrite the grant and tell the owner
        // they are safe while their 2FA is in fact off. Zero rows updated means somebody got there
        // first, which is the disputed path. Not refreshed afterwards -- that cascades and would
        // discard the session invalidation below.
        $claimed = $em->createQuery(
            'UPDATE App\Entity\SupportRequest r
             SET r.status = :closed, r.resolvedAt = :now, r.updatedAt = :now
             WHERE r.id = :id AND r.status = :open'
        )
            ->setParameter('closed', SupportRequestStatus::Closed->value)
            ->setParameter('open', SupportRequestStatus::Open->value)
            ->setParameter('now', $now)
            ->setParameter('id', $request->id, 'ulid')
            ->execute();

        $alreadyActioned = $claimed === 0;
        if (!$alreadyActioned) {
            // Mirror what the UPDATE did, so the entity and the page agree.
            $request->close($now);
        }

        // The owner is telling us somebody else knows their password. True whether or not we already
        // acted, so drop every session and remember-me cookie either way.
        $request->user->invalidateAllSessions();

        if ($alreadyActioned) {
            // The reset already went through and the owner says it was not them. That is an incident
            // to escalate, not a cancellation to report as a success.
            $em->persist(new SupportRequestMessage(
                $request,
                SupportMessageVisibility::Internal,
                'The account owner used the cancellation link AFTER this request was actioned, so they '
                    .'say the reset was not requested by them. Sessions have been invalidated. Treat the '
                    .'account as compromised.',
                null,
            ));
            $em->flush();
            $this->notifier->notifyAdminsOfDisputedRequest($request);
        } else {
            // Recorded as a message rather than just a status, so the queue can tell an owner veto
            // apart from an admin closing the task.
            $em->persist(new SupportRequestMessage(
                $request,
                SupportMessageVisibility::Internal,
                'Cancelled by the account owner through the link in the alert email.',
                null,
            ));
            $em->flush();
        }

        return $this->render('support/cancelled.html.twig', [
            'request' => $request,
            'alreadyActioned' => $alreadyActioned,
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/contact/package-transfer', name: 'support_package_transfer', methods: ['GET', 'POST'])]
    public function packageTransfer(Request $req, #[CurrentUser] User $user): Response
    {
        $data = new PackageTransferSupportRequest();
        $form = $this->createForm(PackageTransferSupportType::class, $data)->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $create = fn (): SupportRequest => SupportRequest::packageTransfer($user, implode("\n", $data->packageNameList()), $data->description);

            if (null !== $response = $this->storeRequest($req, $user, SupportRequestType::PackageTransfer, $create)) {
                return $response;
            }
        }

        return $this->renderRequestForm($form, [
            'heading' => 'Request a package transfer',
            'intro' => 'Ask us to move one or more packages to your account, for example when the current maintainer is unreachable.',
            'submitLabel' => 'Send request',
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/contact/vendor-claim', name: 'support_vendor_claim', methods: ['GET', 'POST'])]
    public function vendorClaim(Request $req, #[CurrentUser] User $user, PackageRepository $packageRepo): Response
    {
        $data = new VendorClaimSupportRequest();
        $data->vendorName = $req->query->getString('vendor');

        $form = $this->createForm(VendorClaimSupportType::class, $data)->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            // Saves the most common pointless ticket: people who could already publish here.
            if (!$packageRepo->isVendorTaken($data->vendorName, $user)) {
                // False covers both "nobody holds it" and "you already maintain a package under it";
                // either way there is nothing for us to hand over.
                $form->get('vendorName')->addError(new \Symfony\Component\Form\FormError(
                    'Nothing is blocking you from publishing under this vendor name, so there is nothing to claim. '
                    .'Submit a package under it and it is yours.'
                ));
            } else {
                $create = fn (): SupportRequest => SupportRequest::vendorClaim($user, $data->vendorName, $data->description);

                if (null !== $response = $this->storeRequest($req, $user, SupportRequestType::VendorClaim, $create)) {
                    return $response;
                }
            }
        }

        return $this->renderRequestForm($form, [
            'heading' => 'Claim a vendor name',
            'intro' => 'Ask for a vendor namespace that already has packages under it but should belong to you.',
            'submitLabel' => 'Send request',
        ]);
    }

    #[IsGranted('ROLE_USER')]
    #[Route(path: '/contact/delete-account', name: 'support_delete_account', methods: ['GET', 'POST'])]
    public function deleteAccount(Request $req, #[CurrentUser] User $user): Response
    {
        // Accounts without packages can already delete themselves; no need for a ticket.
        if ($user->getPackages()->count() === 0) {
            $this->addFlash('info', 'You can delete your account yourself from this page.');

            return $this->redirectToRoute('user_profile', ['name' => $user->getUsername()]);
        }

        $data = new AccountDeletionSupportRequest();
        $form = $this->createForm(AccountDeletionSupportType::class, $data)->handleRequest($req);

        if ($form->isSubmitted() && $form->isValid()) {
            $create = function () use ($user, $data): SupportRequest {
                $disposition = $data->packageDisposition ?? PackageDisposition::Undecided;
                $description = 'Packages: '.$disposition->label();
                if ($disposition === PackageDisposition::Transfer) {
                    $description .= ' ('.$data->transferTo.')';
                }
                if ($data->description !== null && trim($data->description) !== '') {
                    $description .= "\n\n".$data->description;
                }

                return SupportRequest::accountDeletion($user, $description);
            };

            if (null !== $response = $this->storeRequest($req, $user, SupportRequestType::AccountDeletion, $create)) {
                return $response;
            }
        }

        return $this->renderRequestForm($form, [
            'heading' => 'Request account deletion',
            'intro' => 'Your account still maintains packages, so we need to agree what happens to them before it can be deleted.',
            'submitLabel' => 'Send request',
            'packages' => $user->getPackages(),
        ]);
    }

    /**
     * Shared tail of the three logged-in workflows: dedupe, rate limit, persist, notify.
     *
     * Returns a redirect when the request was stored or already exists, or null when the caller
     * should re-render its form with an error flash.
     *
     * @param callable(): SupportRequest $create
     */
    private function storeRequest(Request $req, User $user, SupportRequestType $type, callable $create): ?Response
    {
        $existing = $this->supportRequests->findOpen($user, $type);
        if ($existing !== null) {
            $this->addFlash('info', 'You already have an open request of this kind ('.$existing->publicId.'). We will get back to you by email.');

            return $this->redirectToRoute('support_contact');
        }

        if ($this->rateLimiter->isLimited($user, $type, $req->getClientIp())) {
            $this->addFlash('error', 'You have submitted this request too many times recently. Please email contact@packagist.org instead.');

            return null;
        }

        $request = $create();
        $em = $this->getEM();
        $em->persist($request);

        try {
            $em->flush();
        } catch (UniqueConstraintViolationException) {
            // As above: the violation is the answer, and the EM is closed, so do not read back.
            $this->addFlash('info', 'You already have an open request of this kind. We will get back to you by email.');

            return $this->redirectToRoute('support_contact');
        }

        $this->rateLimiter->recordSubmission($user, $type, $req->getClientIp());
        $this->notifier->notifyAdmins($request);

        $this->addFlash('success', 'Request '.$request->publicId.' received. We will get back to you by email.');

        return $this->redirectToRoute('support_contact');
    }

    /**
     * @param FormInterface<LostTwoFactorSupportRequest>|null $form
     */
    private function renderLostTwoFactor(?FormInterface $form, ?SupportRequest $existing, bool $duplicate = false): Response
    {
        return $this->render('support/lost_two_factor.html.twig', [
            'form' => $form?->createView(),
            'existingRequest' => $existing,
            // Set when we know a request exists but cannot name it, after a lost insert race.
            'duplicate' => $duplicate || $existing !== null,
        ], new Response(
            status: $form !== null && $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        ));
    }

    /**
     * @template T of object
     *
     * @param FormInterface<T>     $form
     * @param array<string, mixed> $context
     */
    private function renderRequestForm(FormInterface $form, array $context): Response
    {
        return $this->render('support/request.html.twig', ['form' => $form->createView(), ...$context], new Response(
            status: $form->isSubmitted() && !$form->isValid() ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK,
        ));
    }

    private function userFromTwoFactorToken(TokenStorageInterface $tokenStorage): User
    {
        // Deliberately not #[CurrentUser] or $this->getUser(): both resolve through the token and
        // would also hand back a User for a fully authenticated session. The token TYPE is what
        // proves the password was just verified and the second factor was not.
        $token = $tokenStorage->getToken();
        if (!$token instanceof TwoFactorTokenInterface) {
            throw $this->createAccessDeniedException('Not in a two-factor authentication process.');
        }

        $tokenUser = $token->getUser();
        if (!$tokenUser instanceof User) {
            throw $this->createAccessDeniedException('Not in a two-factor authentication process.');
        }

        // Identity comes from the token, but its User was deserialized from the session and can be
        // stale -- an account frozen since login still looks fine on it. Reload so the freeze and
        // 2FA checks below see current state.
        $user = $this->getEM()->getRepository(User::class)->find($tokenUser->getId());
        if ($user === null) {
            throw $this->createAccessDeniedException('Not in a two-factor authentication process.');
        }

        return $user;
    }
}
