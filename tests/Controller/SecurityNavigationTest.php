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

use App\Entity\User;
use App\Tests\IntegrationTestCase;

class SecurityNavigationTest extends IntegrationTestCase
{
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = self::createUser('alice', 'alice@example.org');
        $this->store($this->user);
        $this->client->loginUser($this->user);
    }

    public function testHamburgerTargetsTheMainMenuAndIncludesMobileAccountLinks(): void
    {
        $crawler = $this->client->request('GET', '/users/alice/2fa/');
        $toggle = $crawler->filter('button.navbar-toggler');
        $target = $toggle->attr('aria-controls');
        self::assertSame('#'.$target, $toggle->attr('data-bs-target'));
        self::assertSame('false', $toggle->attr('aria-expanded'));
        self::assertCount(1, $crawler->filter('#'.$target));
        self::assertCount(1, $crawler->filter('header .mobile-account-menu a[href="/profile/security"]'));
        self::assertCount(0, $crawler->filter('.security-account-menu'));
        $this->client->restart();
        $crawler = $this->client->request('GET', '/login/');
        self::assertCount(0, $crawler->filter('header .mobile-account-menu'));
    }

    public function testAccountEntryUsesTheUsernameAndProfileIconOnDesktopAndMobile(): void
    {
        $this->user->setUsername('alice & bob');
        self::getEM()->flush();
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/profile/security/authenticator');
        $desktop = $crawler->filter('header .username-link');
        $mobile = $crawler->filter('header .mobile-profile-link');
        foreach ([$desktop, $mobile] as $profile) {
            self::assertSame('alice & bob', $profile->text());
            self::assertSame('Profile: alice & bob', $profile->attr('aria-label'));
            self::assertSame('/profile/', $profile->attr('href'));
            self::assertCount(1, $profile->filter('.icon-user[aria-hidden="true"]'));
        }
        self::assertStringContainsString('alice &amp; bob', $mobile->html());
        self::assertCount(1, $crawler->filter('header .mobile-account-menu a[href="/profile/edit"]'));
        self::assertCount(1, $crawler->filter('header .mobile-account-menu .account-subnavigation-link[href="/profile/edit"]'));
        self::assertCount(0, $crawler->filter('header .mobile-profile-link.account-subnavigation-link'));
        self::assertCount(0, $crawler->filter('header .mobile-account-menu .account-subnavigation-link[href^="/logout"]'));
    }

    public function testSecurityNavigationGroupsPasswordAndSecondFactorSettings(): void
    {
        $pages = [
            '/profile/security' => 'Overview',
            '/users/alice/2fa/' => 'Overview',
            '/profile/security/password' => 'Change password',
            '/profile/security/authenticator' => 'Authenticator app',
        ];
        foreach ($pages as $url => $label) {
            $crawler = $this->client->request('GET', $url);
            self::assertSame(200, $this->client->getResponse()->getStatusCode());
            self::assertSame($label, $crawler->filter('nav[aria-label="Security settings"] a[aria-current="page"]')->text());
            $accountLinks = $crawler->filter('nav[aria-label="Account settings"] a')->each(static fn ($link) => $link->text());
            self::assertContains('Security', $accountLinks);
            self::assertNotContains('Change password', $accountLinks);
            self::assertSame('Security', $crawler->filter('nav[aria-label="Account settings"] li.active > a')->text());
            self::assertCount(0, $crawler->filter('header .mobile-account-menu a[href="/profile/security/password"]'));
            self::assertCount(3, $crawler->filter('.security-tabs a'));
            self::assertStringNotContainsString('Recovery code', $crawler->filter('.security-tabs')->text());
            self::assertStringNotContainsString('Passkeys', $crawler->text());
        }
    }

    public function testOverviewReflectsAuthenticatorStatus(): void
    {
        $crawler = $this->client->request('GET', '/users/alice/2fa/');
        self::assertSame('Not set up', $crawler->filter('[aria-labelledby="authenticator-heading"] .badge')->text());
        self::assertCount(0, $crawler->filter('a[href="/users/alice/2fa/disable"]'));
        $this->user->setTotpSecret('JBSWY3DPEHPK3PXP');
        self::getEM()->flush();
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/users/alice/2fa/');
        self::assertSame('Enabled', $crawler->filter('[aria-labelledby="authenticator-heading"] .badge')->text());
        self::assertCount(0, $crawler->filter('[aria-labelledby="recovery-heading"]'));
        self::assertCount(0, $crawler->filter('a[href="/profile/security/recovery"]'));
        self::assertCount(1, $crawler->filter('a[href="/users/alice/2fa/disable"]'));
    }

    public function testSecurityPagesRequireAuthentication(): void
    {
        $this->client->restart();
        foreach (['/profile/security', '/profile/security/authenticator', '/profile/security/password'] as $url) {
            $this->client->request('GET', $url);
            self::assertSame(302, $this->client->getResponse()->getStatusCode());
            self::assertStringEndsWith('/login/', $this->client->getResponse()->headers->get('Location'));
        }
    }

    public function testAuthenticatorSetupAndConfirmationKeepExistingBehavior(): void
    {
        $crawler = $this->client->request('GET', '/users/alice/2fa/enable');
        self::assertSame('Authenticator app', $crawler->filter('.security-tabs a[aria-current="page"]')->text());
        $form = $crawler->selectButton('Enable authenticator app')->form();
        $form['enable_two_factor_auth[code]'] = '123456';
        $crawler = $this->client->submit($form);
        self::assertSame(422, $this->client->getResponse()->getStatusCode());
        $form = $crawler->selectButton('Enable authenticator app')->form();
        $form['enable_two_factor_auth[code]'] = \App\Tests\Mock\TotpAuthenticatorStub::MOCKED_VALID_CODE;
        $this->client->submit($form);
        self::assertSame('/users/alice/2fa/confirm', $this->client->getResponse()->headers->get('Location'));
        $crawler = $this->client->followRedirect();
        self::assertSame('Authenticator app', $crawler->filter('.security-tabs a[aria-current="page"]')->text());
        self::assertCount(1, $crawler->filter('.two-factor-backup-code'));
        $this->client->request('GET', '/users/alice/2fa/confirm');
        self::assertSame('/profile/security', $this->client->getResponse()->headers->get('Location'));
    }

    public function testSecurityOverviewCannotBeViewedForAnotherUser(): void
    {
        $this->store(self::createUser('bob', 'bob@example.org'));
        $this->client->request('GET', '/users/bob/2fa/');
        self::assertSame(403, $this->client->getResponse()->getStatusCode());
    }

    public function testSecurityTabsAreChildrenOfTheAccountMenuItem(): void
    {
        $this->client->request('GET', '/users/alice/2fa/');
        $menu = self::getContainer()->get(\App\Menu\MenuBuilder::class)->createProfileMenu();
        $security = $menu->getChild('Security');
        self::assertNotNull($security);
        self::assertSame(['Overview', 'Change password', 'Authenticator app'], array_keys($security->getChildren()));
        self::assertCount(1, $security->getExtra('routes'));
        self::assertSame('/profile/security/password', $security->getChild('Change password')->getUri());
    }

    public function testDisablingAuthenticatorReturnsToOwnSecurityOverview(): void
    {
        $this->user->setTotpSecret('JBSWY3DPEHPK3PXP');
        self::getEM()->flush();
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/profile/security/authenticator');
        $crawler = $this->client->click($crawler->selectLink('Remove authenticator app')->link());
        self::assertSame('Authenticator app', $crawler->filter('.security-tabs a[aria-current="page"]')->text());
        self::assertTrue($this->user->isTotpAuthenticationEnabled());
        $crawler = $this->client->request('GET', '/users/alice/2fa/disable?token=invalid');
        self::assertTrue($this->user->isTotpAuthenticationEnabled());
        self::assertSame('/profile/security', $crawler->selectLink('Keep authenticator app')->attr('href'));
        $this->client->click($crawler->selectLink('Remove authenticator app')->link());
        self::assertResponseRedirects('/profile/security');
        self::getEM()->clear();
        $updatedUser = self::getEM()->find(User::class, $this->user->getId());
        self::assertNotNull($updatedUser);
        self::assertFalse($updatedUser->isTotpAuthenticationEnabled());
    }

    public function testSupportCanStillInspectAndDisableAnotherUsersAuthenticator(): void
    {
        $this->user->addRole('ROLE_DISABLE_2FA');
        $other = self::createUser('bob', 'bob@example.org');
        $other->setTotpSecret('JBSWY3DPEHPK3PXP');
        $this->store($other);
        $this->client->loginUser($this->user);
        $crawler = $this->client->request('GET', '/users/bob/2fa/');
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.security-tabs'));
        self::assertCount(0, $crawler->filter('[aria-labelledby="password-heading"]'));
        $crawler = $this->client->click($crawler->selectLink('Remove authenticator app')->link());
        self::assertSame('/users/bob/2fa/', $crawler->selectLink('Keep authenticator app')->attr('href'));
        $this->client->click($crawler->selectLink('Remove authenticator app')->link());
        self::assertResponseRedirects('/users/bob/2fa/');
        self::getEM()->clear();
        $updatedUser = self::getEM()->find(User::class, $other->getId());
        self::assertNotNull($updatedUser);
        self::assertFalse($updatedUser->isTotpAuthenticationEnabled());
    }
}
