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

namespace App\Tests\Controller\Admin;

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\SupportMessageVisibility;
use App\Entity\SupportRequest;
use App\Entity\SupportRequestMessage;
use App\Entity\User;
use App\Support\SupportRequestStatus;
use App\Support\SupportRequestType;
use App\Tests\IntegrationTestCase;

class SupportControllerTest extends IntegrationTestCase
{
    public function testQueueDeniedWithoutAnyActionableType(): void
    {
        // Reaches /admin/, but cannot action any kind of support request.
        $mod = self::createUser('listmod', 'listmod@example.org', roles: ['ROLE_FILTER_LIST_ADMIN']);
        $this->store($mod);

        $this->client->loginUser($mod);
        $this->client->request('GET', '/admin/support/');

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testQueueShowsOnlyTheTypesTheAdminCanAction(): void
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $requester = self::createUser('requester', 'requester@example.org');
        $this->store($admin, $requester);

        $transfer = SupportRequest::packageTransfer($requester, 'acme/thing', 'Please move it.');
        $lost2fa = SupportRequest::lostTwoFactor($requester, null, 'token', null);
        $this->store($transfer, $lost2fa);

        $this->client->loginUser($admin);
        $html = $this->client->request('GET', '/admin/support/')->html();

        self::assertResponseIsSuccessful();
        self::assertStringContainsString($transfer->publicId, $html);
        self::assertStringNotContainsString($lost2fa->publicId, $html);
    }

    public function testDetailOfAnUnactionableTypeIsDenied(): void
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $requester = self::createUser('requester', 'requester@example.org');
        $this->store($admin, $requester);

        $lost2fa = SupportRequest::lostTwoFactor($requester, null, 'token', null);
        $this->store($lost2fa);

        $this->client->loginUser($admin);
        $this->client->request('GET', '/admin/support/'.$lost2fa->publicId);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testInternalNoteIsStoredAndNeverEmailed(): void
    {
        [$admin, $request] = $this->givenTransferRequest();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);

        $form = $crawler->selectButton('Add note')->form();
        $form->setValues(['contents' => 'Checked the repo, looks legit.']);

        $this->client->enableProfiler();
        $this->client->submit($form);
        $this->assertResponseRedirects('/admin/support/'.$request->publicId);

        $this->assertEmailCount(0);

        $message = $this->firstMessage($request);
        self::assertNotNull($message);
        self::assertSame(SupportMessageVisibility::Internal, $message->visibility);
        self::assertSame('Checked the repo, looks legit.', $message->contents);
    }

    public function testReplyIsStoredAndEmailedToTheRequester(): void
    {
        [$admin, $request, $requester] = $this->givenTransferRequest();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);

        $form = $crawler->selectButton('Send reply')->form();
        $form->setValues(['contents' => 'All three packages are yours now.']);

        $this->client->enableProfiler();
        $this->client->submit($form);

        $this->assertEmailCount(1);
        $mail = $this->getMailerMessage();
        self::assertNotNull($mail);
        $this->assertEmailHeaderSame($mail, 'To', $requester->getEmail());
        self::assertStringContainsString('All three packages are yours now.', $mail->getTextBody());

        $message = $this->firstMessage($request);
        self::assertNotNull($message);
        self::assertSame(SupportMessageVisibility::Reply, $message->visibility);
    }

    public function testResolveThenReopen(): void
    {
        [$admin, $request] = $this->givenTransferRequest();

        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/support/'.$request->publicId.'/status', [
            'token' => $this->csrfTokenFor($request),
            'status' => 'resolved',
        ]);
        self::assertSame(SupportRequestStatus::Resolved, $this->reload($request)->status);

        $this->client->request('POST', '/admin/support/'.$request->publicId.'/status', [
            'token' => $this->csrfTokenFor($request),
            'status' => 'open',
        ]);

        $reopened = $this->reload($request);
        self::assertSame(SupportRequestStatus::Open, $reopened->status);
        self::assertNull($reopened->resolvedAt);
    }

    public function testGrantingATwoFactorResetRequiresTheDisable2faRole(): void
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $requester = $this->twoFactorUser();
        $this->store($admin, $requester);

        $request = SupportRequest::lostTwoFactor($requester, null, 'token', null);
        $this->store($request);

        $this->client->loginUser($admin);
        // Denied before the token is even looked at, so its value does not matter here.
        $this->client->request('POST', '/admin/support/'.$request->publicId.'/grant-2fa-reset', ['token' => 'irrelevant']);

        self::assertSame(403, $this->client->getResponse()->getStatusCode());
        self::assertTrue($this->reload($request)->user->isTotpAuthenticationEnabled());
    }

    public function testGrantDisablesTwoFactorResolvesAndNotifiesTheUser(): void
    {
        [$admin, $request, $requester] = $this->givenTwoFactorRequest();

        $this->client->loginUser($admin);
        $this->client->enableProfiler();
        $this->client->request('POST', '/admin/support/'.$request->publicId.'/grant-2fa-reset', ['token' => $this->csrfTokenFor($request)]);

        $this->assertResponseRedirects('/admin/support/'.$request->publicId);

        $reloaded = $this->reload($request);
        self::assertSame(SupportRequestStatus::Resolved, $reloaded->status);
        self::assertNotNull($reloaded->resolvedAt);
        self::assertFalse($reloaded->user->isTotpAuthenticationEnabled());

        // The standard 2FA-disabled notice plus the support reply: they say different things.
        $this->assertEmailCount(2);
        foreach ($this->getMailerMessages() as $mail) {
            $this->assertEmailHeaderSame($mail, 'To', $requester->getEmail());
        }

        $audit = self::getEM()->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::TwoFaAuthenticationDeactivated->value,
            'userId' => $requester->getId(),
        ]);
        self::assertNotNull($audit, 'the deactivation must be attributable to the affected user, not just the actor');
        self::assertSame($admin->getId(), $audit->actorId);
        self::assertStringContainsString($request->publicId, (string) $audit->attributes['reason']);
    }

    public function testGrantIsRefusedWhileTheCoolingOffPeriodRuns(): void
    {
        [$admin, $request] = $this->givenTwoFactorRequest(approvableAt: new \DateTimeImmutable('+24 hours'));

        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/support/'.$request->publicId.'/grant-2fa-reset', ['token' => $this->csrfTokenFor($request)]);

        $reloaded = $this->reload($request);
        self::assertSame(SupportRequestStatus::Open, $reloaded->status);
        self::assertTrue($reloaded->user->isTotpAuthenticationEnabled());
    }

    /** Freezing between the request and the approval must not leave a way back into the account. */
    public function testGrantIsRefusedForAFrozenAccount(): void
    {
        [$admin, $request, $requester] = $this->givenTwoFactorRequest();

        $requester->freeze(\App\Entity\UserFreezeReason::BadActor);
        self::getEM()->flush();

        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/support/'.$request->publicId.'/grant-2fa-reset', ['token' => $this->csrfTokenFor($request)]);

        $reloaded = $this->reload($request);
        self::assertSame(SupportRequestStatus::Open, $reloaded->status);
        self::assertTrue($reloaded->user->isTotpAuthenticationEnabled());
    }

    public function testGrantIsRejectedForOtherRequestTypes(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $requester = self::createUser('requester', 'requester@example.org');
        $this->store($admin, $requester);

        $request = SupportRequest::packageTransfer($requester, 'acme/thing', 'Please move it.');
        $this->store($request);

        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/support/'.$request->publicId.'/grant-2fa-reset', ['token' => $this->csrfTokenFor($request)]);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
    }

    public function testActionsRequireACsrfToken(): void
    {
        [$admin, $request] = $this->givenTransferRequest();

        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/support/'.$request->publicId.'/status', ['status' => 'closed']);

        self::assertSame(400, $this->client->getResponse()->getStatusCode());
        self::assertSame(SupportRequestStatus::Open, $this->reload($request)->status);
    }

    /**
     * @return array{User, SupportRequest, User}
     */
    private function givenTransferRequest(): array
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $requester = self::createUser('requester', 'requester@example.org');
        $this->store($admin, $requester);

        $request = SupportRequest::packageTransfer($requester, "acme/one\nacme/two", 'Please move them.');
        $this->store($request);

        return [$admin, $request, $requester];
    }

    /**
     * @return array{User, SupportRequest, User}
     */
    private function givenTwoFactorRequest(?\DateTimeImmutable $approvableAt = null): array
    {
        $admin = self::createUser('twofaadmin', 'twofaadmin@example.org', roles: ['ROLE_DISABLE_2FA']);
        $requester = $this->twoFactorUser();
        $this->store($admin, $requester);

        $request = SupportRequest::lostTwoFactor($requester, 'I dropped my phone in a lake.', 'token', $approvableAt);
        $this->store($request);

        return [$admin, $request, $requester];
    }

    private function twoFactorUser(): User
    {
        $user = self::createUser('requester', 'requester@example.org');
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');

        return $user;
    }

    /**
     * Reads the token out of the rendered form, the way a browser does. The token storage is
     * session-backed, so it cannot be minted outside a request.
     */
    private function csrfTokenFor(SupportRequest $request): string
    {
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);

        return (string) $crawler->filter('input[name="token"]')->first()->attr('value');
    }

    private function reload(SupportRequest $request): SupportRequest
    {
        $em = self::getEM();
        $em->clear();

        $reloaded = $em->getRepository(SupportRequest::class)->findOneByPublicId($request->publicId);
        self::assertNotNull($reloaded);

        return $reloaded;
    }

    private function firstMessage(SupportRequest $request): ?SupportRequestMessage
    {
        $em = self::getEM();
        $em->clear();

        return $em->getRepository(SupportRequestMessage::class)->findOneBy([]);
    }
}
