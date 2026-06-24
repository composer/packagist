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
        $token = $crawler->filter('#freeze-user-modal input[type="hidden"]')->attr('value');
        $this->client->request('POST', '/users/bob/freeze', [
            'form' => ['_token' => $token],
            'reason' => 'bad_actor',
            'reasonText' => 'malware author',
            'internalReason' => 'ticket #99',
        ]);
        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $bob = $em->getRepository(User::class)->find($userId);
        $this->assertTrue($bob->isFrozen());
        $this->assertSame(UserFreezeReason::BadActor, $bob->getFreezeReason());

        $record = $em->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::UserFrozen->value, 'userId' => $userId]);
        $this->assertNotNull($record);
        $this->assertSame('bad_actor', $record->attributes['reason'] ?? null);
        $this->assertSame('malware author', $record->attributes['reasonText'] ?? null);
        $this->assertSame('ticket #99', $record->attributes['internalReason'] ?? null);
        $this->assertSame('admin', $record->attributes['actor']['username'] ?? null);

        // Unfreeze
        $crawler = $this->client->request('GET', '/users/bob/');
        $token = $crawler->filter('#unfreeze-user-modal input[type="hidden"]')->attr('value');
        $this->client->request('POST', '/users/bob/unfreeze', [
            'form' => ['_token' => $token],
            'reasonText' => 'appeal accepted',
        ]);
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
        $token = $crawler->filter('#freeze-user-modal input[type="hidden"]')->attr('value');
        $this->client->request('POST', '/users/admin/freeze', [
            'form' => ['_token' => $token],
            'reason' => 'spam',
        ]);
        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $admin = $em->getRepository(User::class)->find($adminId);
        $this->assertFalse($admin->isFrozen());
    }
}
