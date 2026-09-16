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

use App\Entity\SupportRequest;
use App\Entity\User;
use App\Entity\UserFreezeReason;
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
        self::assertSame(['acme/console', 'acme/http-client'], $request->packageNameList());
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
        self::assertStringContainsString('abandoned', (string) $request->description);
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
