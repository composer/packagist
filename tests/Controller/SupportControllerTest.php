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

namespace App\Tests\Controller;

use App\Entity\PackageFreezeReason;
use App\Entity\SupportRequest;
use App\Entity\User;
use App\Entity\UserFreezeReason;
use App\Support\Attributes\AccountDeletionAttributes;
use App\Support\Attributes\PackageDeletionAttributes;
use App\Support\Attributes\PackageTransferAttributes;
use App\Support\Attributes\PackageUnfreezeAttributes;
use App\Support\Attributes\PackageUrlChangeAttributes;
use App\Support\PackageDisposition;
use App\Support\SupportRequestStatus;
use App\Support\SupportRequestType;
use App\Tests\IntegrationTestCase;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class SupportControllerTest extends IntegrationTestCase
{
    private const string PASSWORD = 'testtest';

    public function testContactPageListsWorkflowsAndThePlainEmailAddress(): void
    {
        $crawler = $this->client->request('GET', '/contact');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('contact@packagist.org', $crawler->filter('body')->html());
        $this->assertCount(1, $crawler->filter('#lost-2fa'));
        $this->assertCount(1, $crawler->filter('#package-transfer'));
        $this->assertCount(1, $crawler->filter('#vendor-claim'));
        $this->assertCount(1, $crawler->filter('#delete-account'));
        $this->assertCount(1, $crawler->filter('#unfreeze-package'));
        $this->assertCount(1, $crawler->filter('#package-url-change'));
        $this->assertCount(1, $crawler->filter('#delete-packages'));

        $this->assertCount(1, $crawler->filter('a[href="https://phpc.social/@packagist"]'));
        $this->assertCount(1, $crawler->filter('a[href="https://bsky.app/profile/packagist.com"]'));
        $this->assertCount(1, $crawler->filter('a[href="https://x.com/packagist"]'));
        // scoped to the footer list on purpose: the page itself now has an x.com link of its own
        $this->assertCount(0, $crawler->filter('ul.social a[href*="x.com"]'));
    }

    /**
     * The gate on /2fa/lost must be the token TYPE, not merely "some user is present". A fully
     * authenticated session has a User too, so if this ever starts passing the endpoint has become
     * an account-takeover primitive.
     */
    public function testLostTwoFactorIsUnreachableWhenFullyAuthenticated(): void
    {
        $user = self::createUser('someone', 'someone@example.org');
        $this->store($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/2fa/lost');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testLostTwoFactorIsUnreachableWhenLoggedOut(): void
    {
        $this->client->request('GET', '/2fa/lost');

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/login/', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testLostTwoFactorRequestIsCreatedFromTheTwoFactorPrompt(): void
    {
        $user = $this->createTwoFactorUser();

        $this->startTwoFactorLogin($user);

        $crawler = $this->client->request('GET', '/2fa/lost');
        $this->assertResponseIsSuccessful();

        $this->client->enableProfiler();
        $this->client->submit($crawler->selectButton('Request a reset')->form());
        $this->assertResponseIsSuccessful();

        $request = $this->findRequest($user, SupportRequestType::LostTwoFactor);
        self::assertNotNull($request);
        self::assertSame(SupportRequestStatus::Open, $request->status);

        // Owner alert first, then the admin nudge.
        $this->assertEmailCount(2);
        $messages = $this->getMailerMessages();
        $this->assertSame([$user->getEmail()], array_map(static fn ($a) => $a->getAddress(), $messages[0]->getTo()));
        $this->assertStringContainsString($request->publicId, $messages[0]->getTextBody());
        $this->assertStringContainsString('cancel-2fa-request', $messages[0]->getTextBody());
    }

    /** The owner alert goes out over our address, so it must never carry the requester's prose. */
    public function testOwnerAlertDoesNotCarryRequesterText(): void
    {
        $user = $this->createTwoFactorUser();
        $this->startTwoFactorLogin($user);

        $crawler = $this->client->request('GET', '/2fa/lost');
        $form = $crawler->selectButton('Request a reset')->form();
        $form->setValues(['lost_two_factor_request[description]' => 'PLEASE-CLICK-MY-PHISHING-LINK']);

        $this->client->enableProfiler();
        $this->client->submit($form);

        $ownerAlert = $this->getMailerMessages()[0];
        $this->assertStringNotContainsString('PLEASE-CLICK-MY-PHISHING-LINK', $ownerAlert->getTextBody());
    }

    /** support_request_open_uniq must make a resubmit a no-op rather than a second row and mail. */
    public function testLostTwoFactorRequestIsDedupedWhileOneIsOpen(): void
    {
        $user = $this->createTwoFactorUser();
        $this->startTwoFactorLogin($user);

        $crawler = $this->client->request('GET', '/2fa/lost');
        $this->client->submit($crawler->selectButton('Request a reset')->form());

        $first = $this->findRequest($user, SupportRequestType::LostTwoFactor);
        self::assertNotNull($first);

        $this->client->enableProfiler();
        $crawler = $this->client->request('GET', '/2fa/lost');

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString($first->publicId, $crawler->filter('body')->html());
        $this->assertCount(0, $crawler->filter('button:contains("Request a reset"), input[value="Request a reset"]'));
        $this->assertEmailCount(0);

        self::assertCount(1, self::getEM()->getRepository(SupportRequest::class)->findBy(['user' => $user]));
    }

    public function testFrozenAccountCannotRequestATwoFactorReset(): void
    {
        $user = $this->createTwoFactorUser();
        $this->startTwoFactorLogin($user);

        // Re-fetch: $user is detached after the login request, so freezing the old instance would
        // never reach the database.
        $em = self::getEM();
        $managed = $em->getRepository(User::class)->find($user->getId());
        self::assertNotNull($managed);
        $managed->freeze(UserFreezeReason::Temporary);
        $em->flush();

        $this->client->request('GET', '/2fa/lost');

        // Freezing invalidates the half-authenticated session (User::isEqualTo compares the freeze
        // reason), so the request is bounced before it reaches the controller. Asserting the outcome
        // rather than which layer produces it; the controller keeps its own isFrozen() guard behind
        // this in case a future change makes the session survive.
        $this->assertResponseStatusCodeSame(302);
        self::assertNull($this->findRequest($user, SupportRequestType::LostTwoFactor));
    }

    public function testCancelLinkClosesTheRequestAndDropsSessions(): void
    {
        $user = $this->createTwoFactorUser();
        $this->startTwoFactorLogin($user);

        $crawler = $this->client->request('GET', '/2fa/lost');
        $this->client->enableProfiler();
        $this->client->submit($crawler->selectButton('Request a reset')->form());

        $cancelUrl = $this->cancelUrlFromOwnerAlert();

        $em = self::getEM();
        $bustersBefore = $em->getRepository(User::class)->find($user->getId())?->getSessionBuster();

        // The owner clicks this from their inbox, not from the half-logged-in browser: scheb blocks
        // every other route while two-factor authentication is in progress.
        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $cancelUrl);
        $this->assertResponseIsSuccessful();

        // GET must only offer the button; acting on it would let a mail scanner veto the request.
        $em->clear();
        self::assertSame(SupportRequestStatus::Open, $this->reloadRequest()->status);

        $this->client->submit($crawler->selectButton('This was not me — cancel the request')->form());
        $this->assertResponseIsSuccessful();

        $em->clear();
        $cancelled = $this->reloadRequest();
        self::assertSame(SupportRequestStatus::Closed, $cancelled->status);
        self::assertNotSame($bustersBefore, $em->getRepository(User::class)->find($user->getId())?->getSessionBuster());

        // An owner veto has to be tellable apart from an admin closing the task.
        self::assertCount(1, $cancelled->messages);
        self::assertNull($cancelled->messages->first()->author);
    }

    /**
     * The worst case: the owner clicks "this wasn't me" after an admin already granted the reset.
     * They must not be told they are safe.
     */
    public function testCancelLinkAfterTheResetEscalatesInsteadOfClaimingSuccess(): void
    {
        $user = $this->createTwoFactorUser();
        $this->startTwoFactorLogin($user);

        $crawler = $this->client->request('GET', '/2fa/lost');
        $this->client->enableProfiler();
        $this->client->submit($crawler->selectButton('Request a reset')->form());

        $cancelUrl = $this->cancelUrlFromOwnerAlert();

        // Stand in for an admin having granted it.
        $em = self::getEM();
        $granted = $this->reloadRequest();
        $granted->resolve(new \DateTimeImmutable());
        $em->flush();

        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $cancelUrl);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('It has already been actioned.', $crawler->filter('.alert')->text());

        $this->client->enableProfiler();
        $text = $this->client->submit($crawler->selectButton('This was not me — report it')->form())->filter('.alert')->text();

        self::assertStringContainsString('Two-factor authentication was switched off', $text);
        self::assertStringNotContainsString('has not been changed', $text);

        // Admins get told, at high priority.
        $this->assertEmailCount(1);
        $alert = $this->getMailerMessages()[0];
        self::assertStringContainsString('DISPUTED', (string) $alert->getSubject());

        $em->clear();
        $disputed = $this->reloadRequest();
        self::assertSame(SupportRequestStatus::Resolved, $disputed->status, 'the grant stands; disputing it does not rewrite history');
        self::assertCount(1, $disputed->messages);

        // The link deliberately never expires, so a second click must not raise the alarm twice.
        $this->client->enableProfiler();
        $this->client->submit($crawler->selectButton('This was not me — report it')->form());
        $this->assertResponseIsSuccessful();
        $this->assertEmailCount(0);

        $em->clear();
        self::assertCount(1, $this->reloadRequest()->messages);
    }

    /**
     * Losing the conditional UPDATE only means the row was no longer open, and an owner who double
     * clicks their own cancellation loses it against themselves. That is not a takeover.
     */
    public function testCancellingTwiceDoesNotEscalateTheOwnersOwnSecondSubmit(): void
    {
        $user = $this->createTwoFactorUser();
        $this->startTwoFactorLogin($user);

        $crawler = $this->client->request('GET', '/2fa/lost');
        $this->client->enableProfiler();
        $this->client->submit($crawler->selectButton('Request a reset')->form());

        $cancelUrl = $this->cancelUrlFromOwnerAlert();

        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $cancelUrl);
        $form = $crawler->selectButton('This was not me — cancel the request')->form();

        $this->client->submit($form);
        $this->assertResponseIsSuccessful();

        // The same form again, as a refresh or an impatient second click would send it.
        $this->client->enableProfiler();
        $text = $this->client->submit($form)->filter('.alert')->text();

        self::assertStringContainsString('has been cancelled', $text);
        self::assertStringNotContainsString('was switched off', $text);
        $this->assertEmailCount(0);

        self::getEM()->clear();
        self::assertCount(1, $this->reloadRequest()->messages, 'no second note for the same cancellation');
    }

    /**
     * An admin closing without action leaves the account untouched, so a late click on the link is
     * an ordinary cancellation, not a reset to dispute.
     */
    public function testCancelLinkOnAnAdminClosedRequestDoesNotEscalate(): void
    {
        $user = $this->createTwoFactorUser();
        $this->startTwoFactorLogin($user);

        $crawler = $this->client->request('GET', '/2fa/lost');
        $this->client->enableProfiler();
        $this->client->submit($crawler->selectButton('Request a reset')->form());

        $cancelUrl = $this->cancelUrlFromOwnerAlert();

        $em = self::getEM();
        $this->reloadRequest()->close(new \DateTimeImmutable());
        $em->flush();

        $this->client->getCookieJar()->clear();
        $crawler = $this->client->request('GET', $cancelUrl);
        $this->assertResponseIsSuccessful();

        $this->client->enableProfiler();
        $text = $this->client->submit($crawler->selectButton('This was not me — cancel the request')->form())->filter('.alert')->text();

        self::assertStringContainsString('has been cancelled', $text);
        self::assertStringNotContainsString('was switched off', $text);
        $this->assertEmailCount(0);
    }

    public function testCancelLinkRejectsAWrongToken(): void
    {
        $user = $this->createTwoFactorUser();
        $this->startTwoFactorLogin($user);

        $crawler = $this->client->request('GET', '/2fa/lost');
        $this->client->submit($crawler->selectButton('Request a reset')->form());

        $request = $this->findRequest($user, SupportRequestType::LostTwoFactor);
        self::assertNotNull($request);

        $this->client->getCookieJar()->clear();
        $this->client->request('GET', '/contact/cancel-2fa-request/'.$request->publicId.'?token=wrong');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testPackageTransferRequiresLogin(): void
    {
        $this->client->request('GET', '/contact/package-transfer');

        $this->assertResponseStatusCodeSame(302);
        $this->assertStringContainsString('/login/', (string) $this->client->getResponse()->headers->get('Location'));
    }

    public function testPackageTransferRequestStoresPackagesAndRequester(): void
    {
        $user = self::createUser('mover', 'mover@example.org');
        $this->store($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/contact/package-transfer');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'package_transfer_request[packageNames]' => "acme/console\nacme/http-client",
            'package_transfer_request[description]' => 'The current maintainer has not replied in six months.',
        ]);

        $this->client->enableProfiler();
        $this->client->submit($form);
        $this->assertResponseRedirects('/contact');

        $request = $this->findRequest($user, SupportRequestType::PackageTransfer);
        self::assertNotNull($request);
        self::assertSame(['acme/console', 'acme/http-client'], $request->attributesOf(PackageTransferAttributes::class)->packageNames);
        self::assertSame($user->getId(), $request->user->getId());

        // Admins are told there is something to look at, without any of the requester's prose.
        $this->assertEmailCount(1);
        $adminMail = $this->getMailerMessages()[0];
        $this->assertStringContainsString($request->publicId, $adminMail->getTextBody());
        $this->assertStringNotContainsString('has not replied in six months', $adminMail->getTextBody());
    }

    public function testPackageTransferRejectsMalformedPackageNames(): void
    {
        $user = self::createUser('mover', 'mover@example.org');
        $this->store($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/contact/package-transfer');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'package_transfer_request[packageNames]' => 'not a package name',
            'package_transfer_request[description]' => 'Please transfer it.',
        ]);

        $crawler = $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertFormError('These do not look like package names', 'package_transfer_request', $crawler);
    }

    public function testVendorClaimPrefillsTheVendorFromTheQueryString(): void
    {
        $user = self::createUser('claimer', 'claimer@example.org');
        $this->store($user);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/contact/vendor-claim?vendor=acme');

        $this->assertResponseIsSuccessful();
        $this->assertSame('acme', $crawler->filter('#vendor_claim_request_vendorName')->attr('value'));
    }

    public function testVendorClaimIsRejectedWhenTheUserCanAlreadyPublishThere(): void
    {
        $user = self::createUser('owner', 'owner@example.org');
        $package = self::createPackage('acme/thing', 'https://example.org/acme/thing', maintainers: [$user]);
        $this->store($user, $package);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/contact/vendor-claim');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'vendor_claim_request[vendorName]' => 'acme',
            'vendor_claim_request[description]' => 'It is mine.',
        ]);

        $crawler = $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertFormError('Nothing is blocking you from publishing under this vendor name', 'vendor_claim_request', $crawler);
        self::assertNull($this->findRequest($user, SupportRequestType::VendorClaim));
    }

    public function testAccountDeletionSendsPackagelessUsersToSelfService(): void
    {
        $user = self::createUser('leaver', 'leaver@example.org');
        $this->store($user);
        $this->client->loginUser($user);

        $this->client->request('GET', '/contact/delete-account');

        $this->assertResponseRedirects('/users/leaver/');
        self::assertNull($this->findRequest($user, SupportRequestType::AccountDeletion));
    }

    public function testAccountDeletionRequestRecordsThePackageDisposition(): void
    {
        $user = self::createUser('leaver', 'leaver@example.org');
        $package = self::createPackage('leaver/thing', 'https://example.org/leaver/thing', maintainers: [$user]);
        $this->store($user, $package);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/contact/delete-account');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'account_deletion_request[packageDisposition]' => 'abandon',
            'account_deletion_request[acknowledged]' => '1',
        ]);

        $this->client->submit($form);
        $this->assertResponseRedirects('/contact');

        $request = $this->findRequest($user, SupportRequestType::AccountDeletion);
        self::assertNotNull($request);
        $attributes = $request->attributesOf(AccountDeletionAttributes::class);
        self::assertSame(PackageDisposition::Abandon, $attributes->packageDisposition);
        self::assertNull($attributes->transferTo);
    }

    public function testAccountDeletionRecordsWhoThePackagesShouldGoTo(): void
    {
        $user = self::createUser('leaver2', 'leaver2@example.org');
        $package = self::createPackage('leaver2/thing', 'https://example.org/leaver2/thing', maintainers: [$user]);
        $this->store($user, $package);
        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/contact/delete-account');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'account_deletion_request[packageDisposition]' => 'transfer',
            'account_deletion_request[transferTo]' => 'successor',
            'account_deletion_request[acknowledged]' => '1',
        ]);

        $this->client->submit($form);
        $this->assertResponseRedirects('/contact');

        // Read back through a fresh entity manager so this covers the JSON round-trip, not just the
        // value object the factory happened to keep in memory.
        $this->getEM()->clear();
        $request = $this->findRequest($user, SupportRequestType::AccountDeletion);
        self::assertNotNull($request);
        $attributes = $request->attributesOf(AccountDeletionAttributes::class);
        self::assertSame(PackageDisposition::Transfer, $attributes->packageDisposition);
        self::assertSame('successor', $attributes->transferTo);
    }

    private function createTwoFactorUser(string $username = 'twofa'): User
    {
        $user = self::createUser($username, $username.'@example.org');
        $user->setPassword(self::getService(UserPasswordHasherInterface::class)->hashPassword($user, self::PASSWORD));
        $user->setTotpSecret('JBSWY3DPEHPK3PXP');
        $this->store($user);

        return $user;
    }

    /** Logs in with the password only, leaving the session holding a TwoFactorToken. */
    private function startTwoFactorLogin(User $user): void
    {
        $crawler = $this->client->request('GET', '/login/');
        $form = $crawler->selectButton('Log in')->form();
        $form->setValues(['_username' => $user->getUsername(), '_password' => self::PASSWORD]);
        $this->client->submit($form);

        // Two hops: the authenticator sends us to the target path, which then bounces to /2fa.
        while ($this->client->getResponse()->isRedirection()) {
            $this->client->followRedirect();
        }

        $this->assertResponseIsSuccessful();
        $this->assertStringContainsString('Two-Factor Authentication', (string) $this->client->getResponse()->getContent());
    }

    private function cancelUrlFromOwnerAlert(): string
    {
        $ownerAlert = $this->getMailerMessages()[0];
        self::assertSame(1, preg_match('{(/contact/cancel-2fa-request/\S+)}', $ownerAlert->getTextBody(), $match));

        return $match[1];
    }

    private function reloadRequest(): SupportRequest
    {
        $request = self::getEM()->getRepository(SupportRequest::class)->findOneBy(['type' => SupportRequestType::LostTwoFactor->value]);
        self::assertNotNull($request);

        return $request;
    }

    /**
     * @return array{0: User, 1: \App\Entity\Package}
     */
    private function givenFrozenPackage(string $name, PackageFreezeReason $reason, ?User $user = null): array
    {
        $user ??= self::createUser('frosty', 'frosty@example.org');
        $package = self::createPackage($name, 'https://example.org/'.$name, maintainers: [$user]);
        $package->freeze($reason);
        $this->store($user, $package);

        return [$user, $package];
    }

    public function testUnfreezeOnlyOffersGentlyFrozenPackagesTheUserMaintains(): void
    {
        $user = self::createUser('frosty', 'frosty@example.org');
        $stranger = self::createUser('stranger', 'stranger@example.org');
        $this->store($user, $stranger);

        $gentle = self::createPackage('frosty/gone', 'https://example.org/frosty/gone', maintainers: [$user]);
        $gentle->freeze(PackageFreezeReason::Gone);
        $spam = self::createPackage('frosty/spam', 'https://example.org/frosty/spam', maintainers: [$user]);
        $spam->freeze(PackageFreezeReason::Spam);
        $healthy = self::createPackage('frosty/fine', 'https://example.org/frosty/fine', maintainers: [$user]);
        $theirs = self::createPackage('stranger/gone', 'https://example.org/stranger/gone', maintainers: [$stranger]);
        $theirs->freeze(PackageFreezeReason::Gone);
        $this->store($gentle, $spam, $healthy, $theirs);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/unfreeze-package');

        $this->assertResponseIsSuccessful();
        $values = $crawler->filter('input[name="package_unfreeze_request[packageNames][]"]')->extract(['value']);
        self::assertSame(['frosty/gone'], $values);
    }

    /**
     * The checkbox list is the authorization check, so a package the requester cannot appeal must
     * not become selectable just because the query string names it -- including a suppressed one,
     * which would otherwise confirm to a stranger that it exists.
     */
    public function testUnfreezeIgnoresAPackageQueryParamTheUserCannotAppeal(): void
    {
        $user = self::createUser('frosty', 'frosty@example.org');
        $this->store($user);
        $spam = self::createPackage('frosty/spam', 'https://example.org/frosty/spam', maintainers: [$user]);
        $spam->freeze(PackageFreezeReason::Spam);
        $gentle = self::createPackage('frosty/gone', 'https://example.org/frosty/gone', maintainers: [$user]);
        $gentle->freeze(PackageFreezeReason::Gone);
        $this->store($spam, $gentle);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/unfreeze-package?package=frosty/spam');

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('input[name="package_unfreeze_request[packageNames][]"][checked]'));
        self::assertSame(['frosty/gone'], $crawler->filter('input[name="package_unfreeze_request[packageNames][]"]')->extract(['value']));
    }

    public function testUnfreezePreselectsThePackageFromTheQueryString(): void
    {
        [$user] = $this->givenFrozenPackage('frosty/gone', PackageFreezeReason::Gone);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/unfreeze-package?package=frosty/gone');

        $this->assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('input[name="package_unfreeze_request[packageNames][]"][checked]'));
    }

    public function testUnfreezeSendsUsersWithNothingEligibleAway(): void
    {
        $user = self::createUser('frosty', 'frosty@example.org');
        $this->store($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/contact/unfreeze-package');

        $this->assertResponseRedirects('/contact');
    }

    public function testUnfreezeRequestStoresTheTickedPackages(): void
    {
        [$user] = $this->givenFrozenPackage('frosty/gone', PackageFreezeReason::Gone);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/unfreeze-package');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'package_unfreeze_request[packageNames]' => ['frosty/gone'],
            'package_unfreeze_request[description]' => 'The repository is back up, it was a hosting outage.',
        ]);

        $this->client->submit($form);
        $this->assertResponseRedirects('/contact');

        $request = $this->findRequest($user, SupportRequestType::PackageUnfreeze);
        self::assertNotNull($request);
        self::assertSame(['frosty/gone'], $request->attributesOf(PackageUnfreezeAttributes::class)->packageNames);
    }

    public function testUnfreezeRejectsAPackageOutsideTheChoiceList(): void
    {
        $user = self::createUser('frosty', 'frosty@example.org');
        $stranger = self::createUser('stranger', 'stranger@example.org');
        $this->store($user, $stranger);
        $mine = self::createPackage('frosty/gone', 'https://example.org/frosty/gone', maintainers: [$user]);
        $mine->freeze(PackageFreezeReason::Gone);
        $theirs = self::createPackage('stranger/gone', 'https://example.org/stranger/gone', maintainers: [$stranger]);
        $theirs->freeze(PackageFreezeReason::Gone);
        $this->store($mine, $theirs);

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/unfreeze-package');
        $token = $crawler->filter('input[name="package_unfreeze_request[_token]"]')->attr('value');

        $this->client->request('POST', '/contact/unfreeze-package', ['package_unfreeze_request' => [
            'packageNames' => ['stranger/gone'],
            'description' => 'Give me that one instead.',
            '_token' => $token,
        ]]);

        $this->assertResponseStatusCodeSame(422);
        self::assertNull($this->findRequest($user, SupportRequestType::PackageUnfreeze));
    }

    public function testPackageUrlChangePrefillsFromTheQueryString(): void
    {
        $user = self::createUser('mover', 'mover@example.org');
        $this->store($user);
        $this->store(self::createPackage('mover/thing', 'https://example.org/mover/thing', maintainers: [$user]));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/package-url-change?package=mover/thing&repository=https://example.org/mover/moved');

        $this->assertResponseIsSuccessful();
        self::assertSame('https://example.org/mover/moved', $crawler->filter('#package_url_change_request_repository')->attr('value'));
        self::assertCount(1, $crawler->filter('#package_url_change_request_packageName option[value="mover/thing"][selected]'));
    }

    public function testPackageUrlChangeRejectsAPackageTheUserDoesNotMaintain(): void
    {
        $user = self::createUser('mover', 'mover@example.org');
        $stranger = self::createUser('stranger', 'stranger@example.org');
        $this->store($user, $stranger);
        $this->store(self::createPackage('mover/thing', 'https://example.org/mover/thing', maintainers: [$user]));
        $this->store(self::createPackage('stranger/thing', 'https://example.org/stranger/thing', maintainers: [$stranger]));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/package-url-change');
        $token = $crawler->filter('input[name="package_url_change_request[_token]"]')->attr('value');

        $this->client->request('POST', '/contact/package-url-change', ['package_url_change_request' => [
            'packageName' => 'stranger/thing',
            'repository' => 'https://example.org/attacker/thing',
            'description' => 'It moved, honest.',
            '_token' => $token,
        ]]);

        $this->assertResponseStatusCodeSame(422);
        self::assertNull($this->findRequest($user, SupportRequestType::PackageUrlChange));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unreachableRepositoryUrls(): iterable
    {
        yield 'plain http' => ['http://example.org/mover/moved'];
        yield 'ip literal' => ['https://127.0.0.1/mover/moved'];
        yield 'localhost' => ['https://localhost/mover/moved'];
        yield 'explicit port' => ['https://example.org:8080/mover/moved'];
        yield 'embedded credentials' => ['https://user:pass@example.org/mover/moved'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('unreachableRepositoryUrls')]
    public function testPackageUrlChangeRejectsUrlsItCouldNeverActOn(string $url): void
    {
        $user = self::createUser('mover', 'mover@example.org');
        $this->store($user);
        $this->store(self::createPackage('mover/thing', 'https://example.org/mover/thing', maintainers: [$user]));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/package-url-change');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'package_url_change_request[packageName]' => 'mover/thing',
            'package_url_change_request[repository]' => $url,
            'package_url_change_request[description]' => 'It moved.',
        ]);

        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        self::assertNull($this->findRequest($user, SupportRequestType::PackageUrlChange));
    }

    public function testPackageUrlChangeRejectsTheUrlItAlreadyHas(): void
    {
        $user = self::createUser('mover', 'mover@example.org');
        $this->store($user);
        $this->store(self::createPackage('mover/thing', 'https://example.org/mover/thing', maintainers: [$user]));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/package-url-change');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'package_url_change_request[packageName]' => 'mover/thing',
            'package_url_change_request[repository]' => 'https://example.org/mover/thing',
            'package_url_change_request[description]' => 'It moved.',
        ]);

        $crawler = $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertFormError('already the repository URL', 'package_url_change_request', $crawler);
    }

    /**
     * One open request per type is a database invariant, but a GitHub org rename breaks every
     * package under it at once, so the second filing has to land somewhere rather than bounce.
     */
    public function testPackageUrlChangeAppendsToAnOpenRequest(): void
    {
        $user = self::createUser('mover', 'mover@example.org');
        $this->store($user);
        $this->store(self::createPackage('mover/one', 'https://example.org/mover/one', maintainers: [$user]));
        $this->store(self::createPackage('mover/two', 'https://example.org/mover/two', maintainers: [$user]));

        $this->client->loginUser($user);
        foreach (['one', 'two'] as $which) {
            $crawler = $this->client->request('GET', '/contact/package-url-change');
            $form = $crawler->selectButton('Send request')->form();
            $form->setValues([
                'package_url_change_request[packageName]' => 'mover/'.$which,
                'package_url_change_request[repository]' => 'https://example.org/moved/'.$which,
                'package_url_change_request[description]' => 'The org was renamed.',
            ]);
            $this->client->submit($form);
            $this->assertResponseRedirects('/contact');
        }

        $request = $this->findRequest($user, SupportRequestType::PackageUrlChange);
        self::assertNotNull($request);
        self::assertSame(['mover/one', 'mover/two'], $request->attributesOf(PackageUrlChangeAttributes::class)->names());
    }

    public function testDeletePackagesOnlyOffersTheUsersOwnPackages(): void
    {
        $user = self::createUser('owner', 'owner@example.org');
        $stranger = self::createUser('stranger', 'stranger@example.org');
        $this->store($user, $stranger);
        $this->store(self::createPackage('owner/thing', 'https://example.org/owner/thing', maintainers: [$user]));
        $this->store(self::createPackage('stranger/thing', 'https://example.org/stranger/thing', maintainers: [$stranger]));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/delete-packages');

        $this->assertResponseIsSuccessful();
        self::assertSame(['owner/thing'], $crawler->filter('input[name="package_deletion_request[packageNames][]"]')->extract(['value']));
    }

    public function testDeletePackagesStoresTheTickedPackages(): void
    {
        $user = self::createUser('owner', 'owner@example.org');
        $this->store($user);
        $this->store(self::createPackage('owner/one', 'https://example.org/owner/one', maintainers: [$user]));
        $this->store(self::createPackage('owner/two', 'https://example.org/owner/two', maintainers: [$user]));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/delete-packages');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'package_deletion_request[packageNames]' => ['owner/one', 'owner/two'],
            'package_deletion_request[description]' => 'These were a mistake, they duplicate another package.',
            'package_deletion_request[acknowledged]' => '1',
        ]);

        $this->client->submit($form);
        $this->assertResponseRedirects('/contact');

        $request = $this->findRequest($user, SupportRequestType::PackageDeletion);
        self::assertNotNull($request);
        self::assertSame(['owner/one', 'owner/two'], $request->attributesOf(PackageDeletionAttributes::class)->packageNames);
    }

    public function testDeletePackagesRequiresTheAcknowledgement(): void
    {
        $user = self::createUser('owner', 'owner@example.org');
        $this->store($user);
        $this->store(self::createPackage('owner/one', 'https://example.org/owner/one', maintainers: [$user]));

        $this->client->loginUser($user);
        $crawler = $this->client->request('GET', '/contact/delete-packages');
        $form = $crawler->selectButton('Send request')->form();
        $form->setValues([
            'package_deletion_request[packageNames]' => ['owner/one'],
            'package_deletion_request[description]' => 'It was a mistake.',
        ]);

        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(422);
        self::assertNull($this->findRequest($user, SupportRequestType::PackageDeletion));
    }

    public function testDeletePackagesSendsUsersWithNoPackagesAway(): void
    {
        $user = self::createUser('owner', 'owner@example.org');
        $this->store($user);

        $this->client->loginUser($user);
        $this->client->request('GET', '/contact/delete-packages');

        $this->assertResponseRedirects('/contact');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function loggedInWorkflowPaths(): iterable
    {
        yield 'unfreeze' => ['/contact/unfreeze-package'];
        yield 'url change' => ['/contact/package-url-change'];
        yield 'deletion' => ['/contact/delete-packages'];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('loggedInWorkflowPaths')]
    public function testNewWorkflowsRequireLogin(string $path): void
    {
        $this->client->request('GET', $path);

        $this->assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->client->getResponse()->headers->get('Location'));
    }

    private function findRequest(User $user, SupportRequestType $type): ?SupportRequest
    {
        $em = self::getEM();
        $em->clear();

        return $em->getRepository(SupportRequest::class)->findOneBy([
            'user' => $em->getRepository(User::class)->find($user->getId()),
            'type' => $type->value,
        ]);
    }
}
