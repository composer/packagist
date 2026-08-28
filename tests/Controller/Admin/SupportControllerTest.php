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

use App\Log\AuditLogEventType;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\SupportRequest;
use App\Entity\SupportRequestMessage;
use App\Entity\User;
use App\Support\Attributes\LostTwoFactorAttributes;
use App\Support\Attributes\PackageTransferAttributes;
use App\Support\Attributes\VendorClaimAttributes;
use App\Support\SupportRequestStatus;
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

        $transfer = SupportRequest::create($requester, 'Please move it.', new PackageTransferAttributes(['acme/thing']));
        $lost2fa = SupportRequest::create($requester, null, LostTwoFactorAttributes::fromCancelToken('token', null));
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

        $lost2fa = SupportRequest::create($requester, null, LostTwoFactorAttributes::fromCancelToken('token', null));
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
        self::assertTrue($message->internal);
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
        self::assertFalse($message->internal);
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

        $request = SupportRequest::create($requester, null, LostTwoFactorAttributes::fromCancelToken('token', null));
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
        $token = $this->csrfTokenFor($request);

        $this->client->enableProfiler();
        $this->client->request('POST', '/admin/support/'.$request->publicId.'/grant-2fa-reset', ['token' => $token]);

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
            'type' => AuditLogEventType::TwoFactorAuthenticationDeactivated->value,
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

        $request = SupportRequest::create($requester, 'Please move it.', new PackageTransferAttributes(['acme/thing']));
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
     * The count comes from a grouped query rather than the message collection, so it is the kind of
     * thing that can silently come back empty while every row still renders a plausible "0".
     */
    public function testQueueShowsTheRealNoteCountPerRow(): void
    {
        [$admin, $request] = $this->givenTransferRequest();

        $this->store(
            SupportRequestMessage::internalNote($request, 'Checked the repo.', $admin),
            SupportRequestMessage::reply($request, 'Asked them to confirm.', $admin),
        );

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/support/');

        self::assertResponseIsSuccessful();
        self::assertSame('2', trim($crawler->filter('tbody tr')->first()->filter('td')->eq(4)->text()));
    }

    /**
     * Both halves matter: an unescaped % matches every row, and escaping it with the wrong character
     * matches none. Only a literal % passes both assertions.
     */
    public function testQueueSearchTreatsWildcardsAsLiteralText(): void
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $odd = self::createUser('oddone', 'oddone@example.org');
        $plain = self::createUser('plainone', 'plainone@example.org');
        $this->store($admin, $odd, $plain);

        $withWildcard = SupportRequest::create($odd, 'Please move it.', new PackageTransferAttributes(['acme/th%ing']));
        $withoutWildcard = SupportRequest::create($plain, 'Please move it.', new PackageTransferAttributes(['acme/thing']));
        $this->store($withWildcard, $withoutWildcard);

        $this->client->loginUser($admin);

        $html = $this->client->request('GET', '/admin/support/?search=%25')->html();
        self::assertResponseIsSuccessful();
        self::assertStringContainsString($withWildcard->publicId, $html, 'a literal % should still find the row containing one');
        self::assertStringNotContainsString($withoutWildcard->publicId, $html, '% must not act as a wildcard');

        // An ordinary term keeps working.
        $html = $this->client->request('GET', '/admin/support/?search=plainone')->html();
        self::assertStringContainsString($withoutWildcard->publicId, $html);
        self::assertStringNotContainsString($withWildcard->publicId, $html);
    }

    /**
     * js/search.js runs on every page and reads `q`, `query`, `type` and `tags` straight off the
     * query string: it redirects to /search/ when it sees a filter without a query, and otherwise
     * un-hides the package search over whatever page you are on. Neither shows up in a server-side
     * assertion, so this guards the filter form's names instead.
     */
    public function testQueueFilterNamesDoNotCollideWithTheGlobalSearch(): void
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $this->store($admin);
        $this->client->loginUser($admin);

        $crawler = $this->client->request('GET', '/admin/support/');
        self::assertResponseIsSuccessful();

        // Scoped to the queue's own form: the layout's package search box legitimately owns `query`,
        // which is the whole reason these two namespaces must not overlap.
        $filters = $crawler->filter('form[action="/admin/support/"]');
        self::assertCount(1, $filters);

        foreach (['q', 'query', 'type', 'tags'] as $reserved) {
            self::assertCount(0, $filters->filter('[name="'.$reserved.'"]'), $reserved.' is read by js/search.js and must not name a support queue filter');
        }
        self::assertCount(1, $filters->filter('[name="search"]'));
        self::assertCount(1, $filters->filter('[name="requestType"]'));
    }

    public function testTransferButtonHandsThePackageToTheRequester(): void
    {
        [$admin, $request, $requester] = $this->givenTransferRequest();
        $previous = self::createUser('goneaway', 'goneaway@example.org');
        $this->store($previous);
        $one = self::createPackage('acme/one', 'https://example.org/acme/one', maintainers: [$previous]);
        $two = self::createPackage('acme/two', 'https://example.org/acme/two', maintainers: [$previous]);
        $this->store($one, $two);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);

        $this->client->enableProfiler();
        $this->client->submit($crawler->selectButton('Transfer to requester')->form());
        $this->assertResponseRedirects('/admin/support/'.$request->publicId);

        // transferPackage() is called with notifyNewMaintainers, so the requester hears about it even
        // if the admin never gets round to writing a reply.
        $this->assertEmailCount(1);

        $em = self::getEM();
        $em->clear();
        $reloaded = $em->getRepository(Package::class)->findOneBy(['name' => 'acme/one']);
        self::assertNotNull($reloaded);
        // A transfer hands the package over rather than adding a co-maintainer, so the unreachable
        // maintainer the request was filed about must be gone.
        self::assertSame(['requester'], array_map(static fn (User $u): string => $u->getUsername(), $reloaded->getMaintainers()->toArray()));

        $message = $this->firstMessage($request);
        self::assertNotNull($message);
        self::assertTrue($message->internal);
        self::assertSame('Transferred acme/one to requester.', $message->contents);

        // The row now reports the handover instead of offering it again, while acme/two -- requested,
        // real, and still on the old maintainer -- keeps its button. Without that second row this
        // would also pass if the table simply lost every button.
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);
        $rows = $crawler->filter('table tbody tr');
        self::assertCount(1, $rows->eq(0)->filter('.badge:contains("Transferred")'));
        self::assertCount(0, $rows->eq(0)->filter('button'));
        self::assertCount(0, $rows->eq(1)->filter('.badge:contains("Transferred")'));
        self::assertCount(1, $rows->eq(1)->filter('button'));
    }

    /**
     * The queue's role can transfer any package from the package page, so this is not a privilege
     * boundary -- it stops a support action touching a package the request never mentioned.
     */
    public function testTransferRejectsAPackageTheRequestDoesNotList(): void
    {
        [$admin, $request] = $this->givenTransferRequest();
        $unrelated = self::createUser('bystander', 'bystander@example.org');
        $this->store($unrelated);
        $package = self::createPackage('acme/unrelated', 'https://example.org/acme/unrelated', maintainers: [$unrelated]);
        $this->store($package);

        $this->client->loginUser($admin);
        $this->client->request('POST', '/admin/support/'.$request->publicId.'/transfer-package', [
            'token' => $this->csrfTokenFor($request),
            'package' => 'acme/unrelated',
        ]);
        $this->assertResponseStatusCodeSame(400);

        $em = self::getEM();
        $em->clear();
        $reloaded = $em->getRepository(Package::class)->findOneBy(['name' => 'acme/unrelated']);
        self::assertNotNull($reloaded);
        self::assertSame(['bystander'], array_map(static fn (User $u): string => $u->getUsername(), $reloaded->getMaintainers()->toArray()));
    }

    public function testTransferIsNotOfferedOnceTheRequestIsDealtWith(): void
    {
        [$admin, $request] = $this->givenTransferRequest();
        $package = self::createPackage('acme/one', 'https://example.org/acme/one', maintainers: [$admin]);
        $request->resolve(new \DateTimeImmutable());
        $this->store($package, $request);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);
        self::assertCount(0, $crawler->filter('button:contains("Transfer to requester")'));

        $this->client->request('POST', '/admin/support/'.$request->publicId.'/transfer-package', [
            'token' => $this->csrfTokenFor($request),
            'package' => 'acme/one',
        ]);
        $this->assertResponseRedirects('/admin/support/'.$request->publicId);

        $em = self::getEM();
        $em->clear();
        $reloaded = $em->getRepository(Package::class)->findOneBy(['name' => 'acme/one']);
        self::assertNotNull($reloaded);
        self::assertSame(['pkgadmin'], array_map(static fn (User $u): string => $u->getUsername(), $reloaded->getMaintainers()->toArray()));
    }

    public function testPackageTransferShowsEveryVendorTheRequestReachesInto(): void
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $requester = self::createUser('requester', 'requester@example.org');
        $incumbent = self::createUser('incumbent', 'incumbent@example.org');
        $this->store($admin, $requester, $incumbent);

        $asked = self::createPackage('acme/console', 'https://example.org/acme/console', maintainers: [$incumbent]);
        // Not asked for, but the same namespace, which is the context the panel exists to give.
        $sibling = self::createPackage('acme/sibling', 'https://example.org/acme/sibling', maintainers: [$incumbent]);
        $otherVendor = self::createPackage('widgets/thing', 'https://example.org/widgets/thing', maintainers: [$incumbent]);
        $elsewhere = self::createPackage('unrelated/thing', 'https://example.org/unrelated/thing', maintainers: [$incumbent]);

        $request = SupportRequest::create($requester, 'Both please.', new PackageTransferAttributes(['acme/console', 'widgets/thing']));
        $this->store($asked, $sibling, $otherVendor, $elsewhere, $request);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);
        self::assertResponseIsSuccessful();

        // One panel per distinct vendor in the request, and none for a vendor it never mentions.
        self::assertCount(2, $crawler->filter('[id^="vendor-packages-"]'));

        $acme = $crawler->filter('#vendor-packages-1');
        self::assertStringContainsString('acme/sibling', $acme->html());
        self::assertStringNotContainsString('unrelated/thing', $acme->html());
        // Exactly one badge: acme/console is in the request, acme/sibling is only context.
        $badged = $acme->filter('tbody tr')->reduce(static fn ($row): bool => $row->filter('.badge:contains("requested")')->count() > 0);
        self::assertCount(1, $badged);
        self::assertStringContainsString('acme/console', $badged->text());

        self::assertStringContainsString('widgets/thing', $crawler->filter('#vendor-packages-2')->html());
    }

    public function testPackageTransferAsksAboutEachVendorOnlyOnce(): void
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $requester = self::createUser('requester', 'requester@example.org');
        $this->store($admin, $requester);

        $one = self::createPackage('acme/one', 'https://example.org/acme/one', maintainers: [$requester]);
        $two = self::createPackage('acme/two', 'https://example.org/acme/two', maintainers: [$requester]);
        $request = SupportRequest::create($requester, 'Both.', new PackageTransferAttributes(['acme/one', 'acme/two']));
        $this->store($one, $two, $request);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);
        self::assertResponseIsSuccessful();

        self::assertCount(1, $crawler->filter('[id^="vendor-packages-"]'));
    }

    public function testVendorClaimShowsWhoAlreadyPublishesUnderTheVendor(): void
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $claimant = self::createUser('claimant', 'claimant@example.org');
        $incumbent = self::createUser('incumbent', 'incumbent@example.org');
        $this->store($admin, $claimant, $incumbent);

        $mine = self::createPackage('acme/console', 'https://example.org/acme/console', maintainers: [$incumbent]);
        // Same maintainer, different namespace: it must not leak into the acme context.
        $other = self::createPackage('other/thing', 'https://example.org/other/thing', maintainers: [$incumbent]);
        $request = SupportRequest::create($claimant, 'The acme name is mine.', new VendorClaimAttributes('acme'));
        $this->store($mine, $other, $request);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);
        self::assertResponseIsSuccessful();

        $panel = $crawler->filter('#vendor-packages');
        self::assertCount(1, $panel);
        self::assertStringContainsString('acme/console', $panel->html());
        self::assertStringContainsString('incumbent', $panel->html());
        self::assertStringNotContainsString('other/thing', $panel->html());
    }

    public function testVendorClaimSaysSoWhenTheNamespaceIsUnused(): void
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $claimant = self::createUser('claimant', 'claimant@example.org');
        $this->store($admin, $claimant);

        $request = SupportRequest::create($claimant, 'Nobody uses it.', new VendorClaimAttributes('freename'));
        $this->store($request);

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/admin/support/'.$request->publicId);
        self::assertResponseIsSuccessful();

        self::assertStringContainsString('nothing is published under it', $crawler->filter('body')->html());
        self::assertCount(0, $crawler->filter('#vendor-packages'));
    }

    /**
     * @return array{User, SupportRequest, User}
     */
    private function givenTransferRequest(): array
    {
        $admin = self::createUser('pkgadmin', 'pkgadmin@example.org', roles: ['ROLE_EDIT_PACKAGES']);
        $requester = self::createUser('requester', 'requester@example.org');
        $this->store($admin, $requester);

        $request = SupportRequest::create($requester, 'Please move them.', new PackageTransferAttributes(['acme/one', 'acme/two']));
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

        $request = SupportRequest::create($requester, 'I dropped my phone in a lake.', LostTwoFactorAttributes::fromCancelToken('token', $approvableAt));
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

        $reloaded = $em->getRepository(SupportRequest::class)->findOneByPublicId($request->publicId);
        self::assertNotNull($reloaded);

        return $em->getRepository(SupportRequestMessage::class)->findOneBy(
            ['request' => $reloaded],
            ['createdAt' => 'ASC'],
        );
    }
}
