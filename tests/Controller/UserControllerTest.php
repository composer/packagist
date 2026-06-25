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

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\Job;
use App\Entity\User;
use App\Entity\UserFreezeReason;
use App\Tests\IntegrationTestCase;
use App\Tests\Mock\TotpAuthenticatorStub;
use PHPUnit\Framework\Attributes\TestWith;

class UserControllerTest extends IntegrationTestCase
{
    public function testEnableTwoFactorCode(): void
    {
        $user = self::createUser();
        $this->store($user);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', \sprintf('/users/%s/2fa/enable', $user->getUsername()));
        $form = $crawler->selectButton('Enable Two-Factor Authentication')->form();
        $form->setValues([
            'enable_two_factor_auth[code]' => 123456,
        ]);

        $crawler = $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422);

        $form = $crawler->selectButton('Enable Two-Factor Authentication')->form();
        $form->setValues([
            'enable_two_factor_auth[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE,
        ]);

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $this->assertTrue($em->getRepository(User::class)->find($user->getId())->isTotpAuthenticationEnabled());
    }

    #[TestWith([true])]
    #[TestWith([false])]
    public function testDeleteUserAsAdmin(bool $deletedByAdmin): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $bob = self::createUser('bob', 'bob@example.org');
        $this->store($admin, $bob);

        $userId = $bob->getId();

        $actor = $deletedByAdmin ? $admin : $bob;
        $actorName = $actor->getUsernameCanonical();
        $this->client->loginUser($actor);
        $crawler = $this->client->request('GET', '/users/bob/');
        $form = $crawler->selectButton('Delete Account Permanently')->form();

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $deletedUser = $em->getRepository(User::class)->findOneBy(['username' => 'bob']);
        $this->assertNull($deletedUser);

        $record = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::UserDeleted->value, 'userId' => $userId]);
        $this->assertNotNull($record);
        $this->assertSame('bob', $record->attributes['user']['username'] ?? null);
        $this->assertSame($actorName, $record->attributes['actor']['username'] ?? null);
    }

    public function testFreezeAndUnfreezeUserAsAdmin(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $bob = self::createUser('bob', 'bob@example.org');
        $this->store($admin, $bob);
        $userId = $bob->getId();

        $this->client->loginUser($admin);

        // Freeze
        $crawler = $this->client->request('GET', '/users/bob/');
        $form = $crawler->filter('#freeze-user-modal form')->form();
        $form['freeze[reason]'] = 'bad_actor';
        $form['freeze[reasonText]'] = 'malware author';
        $form['freeze[internalReason]'] = 'ticket #99';
        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $bob = $em->getRepository(User::class)->find($userId);
        $this->assertSame(UserFreezeReason::BadActor, $bob->getFreezeReason());

        $record = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::UserFrozen->value, 'userId' => $userId]);
        $this->assertNotNull($record);
        $this->assertSame('bad_actor', $record->attributes['reason'] ?? null);
        $this->assertSame('malware author', $record->attributes['reasonText'] ?? null);
        $this->assertSame('ticket #99', $record->attributes['internalReason'] ?? null);
        $this->assertSame('admin', $record->attributes['actor']['username'] ?? null);

        // Unfreeze
        $crawler = $this->client->request('GET', '/users/bob/');
        $form = $crawler->filter('#unfreeze-user-modal form')->form();
        $form['unfreeze[reasonText]'] = 'appeal accepted';
        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $em->clear();
        $bob = $em->getRepository(User::class)->find($userId);
        $this->assertFalse($bob->isFrozen());

        $record = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::UserUnfrozen->value, 'userId' => $userId]);
        $this->assertNotNull($record);
        $this->assertSame('appeal accepted', $record->attributes['reasonText'] ?? null);
    }

    public function testCannotFreezeSelf(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);
        $adminId = $admin->getId();

        $this->client->loginUser($admin);
        $crawler = $this->client->request('GET', '/users/admin/');
        $form = $crawler->filter('#freeze-user-modal form')->form();
        $form['freeze[reason]'] = 'spam';
        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $admin = $em->getRepository(User::class)->find($adminId);
        $this->assertFalse($admin->isFrozen());
    }

    public function testFreezeAsSpamWithPurgeSchedulesPackagePurge(): void
    {
        $mod = self::createUser('mod', 'mod@example.org', roles: ['ROLE_ANTISPAM']);
        $bob = self::createUser('bob', 'bob@example.org');
        $package = self::createPackage('test/bobpkg', 'https://example.org/bobpkg', maintainers: [$bob]);
        $this->store($mod, $bob, $package);
        $userId = $bob->getId();

        $this->client->loginUser($mod);
        $crawler = $this->client->request('GET', '/users/bob/');
        $form = $crawler->filter('#freeze-user-modal form')->form();
        $form['freeze[reason]'] = 'spam';
        $form['freeze[purgePackages]']->tick();
        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $this->assertSame(UserFreezeReason::Spam, $em->getRepository(User::class)->find($userId)->getFreezeReason());

        $job = $em->getRepository(Job::class)->findOneBy(['type' => 'package:purge']);
        $this->assertNotNull($job, 'a package:purge job should be scheduled');
        $this->assertSame('test/bobpkg', $job->getPayload()['name']);
    }

    public function testAntispamModeratorCannotFreezeAsBadActor(): void
    {
        // An anti-spam moderator's freeze form only offers the spam reason; a forged bad_actor
        // submission is rejected by the form (invalid choice), so the account stays unfrozen.
        $mod = self::createUser('mod', 'mod@example.org', roles: ['ROLE_ANTISPAM']);
        $bob = self::createUser('bob', 'bob@example.org');
        $this->store($mod, $bob);
        $userId = $bob->getId();

        $this->client->loginUser($mod);
        $crawler = $this->client->request('GET', '/users/bob/');
        $token = $crawler->filter('#freeze-user-modal input[name="freeze[_token]"]')->attr('value');
        $this->client->request('POST', '/users/bob/freeze', [
            'freeze' => ['_token' => $token, 'reason' => 'bad_actor'],
        ]);
        $this->assertResponseStatusCodeSame(302);

        self::getEM()->clear();
        $this->assertFalse(self::getEM()->getRepository(User::class)->find($userId)->isFrozen());
    }

    public function testAntispamModeratorCannotUnfreezeBadActorFreeze(): void
    {
        $mod = self::createUser('mod', 'mod@example.org', roles: ['ROLE_ANTISPAM']);
        $bob = self::createUser('bob', 'bob@example.org');
        $bob->freeze(UserFreezeReason::BadActor);
        $this->store($mod, $bob);
        $userId = $bob->getId();

        $this->client->loginUser($mod);
        // The unfreeze permission is checked before CSRF, so a bare POST is enough to assert the 403.
        $this->client->request('POST', '/users/bob/unfreeze', ['unfreeze' => ['_token' => 'x']]);
        $this->assertResponseStatusCodeSame(403);

        self::getEM()->clear();
        $this->assertTrue(self::getEM()->getRepository(User::class)->find($userId)->isFrozen());
    }

    public function testMarkSpammerRouteIsGone(): void
    {
        $admin = self::createUser('admin', 'admin@example.org', roles: ['ROLE_ADMIN']);
        $this->store($admin);

        $this->client->loginUser($admin);
        $this->client->request('POST', '/spammers/bob/');
        $this->assertResponseStatusCodeSame(404);
    }
}
