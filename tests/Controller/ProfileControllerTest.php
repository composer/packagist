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
use App\Tests\IntegrationTestCase;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

class ProfileControllerTest extends IntegrationTestCase
{
    public function testEditProfile(): void
    {
        $user = self::createUser();
        $oldEmail = $user->getEmail();
        $oldUsername = $user->getUsername();
        $this->store($user);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/edit');

        $form = $crawler->selectButton('Update')->form();
        $this->client->submit($form, [
            'packagist_user_profile[email]' => $newEmail = 'new-email@example.org',
            'packagist_user_profile[username]' => $newUsername = 'newusername',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $recipients = array_map(fn (Email $mail) => $mail->getTo()[0]->getAddress(), $this->getMailerMessages());
        $this->assertEqualsCanonicalizing([$oldEmail, $newEmail], $recipients, 'Notification should have been sent to both old and new email');

        $em = self::getEM();
        $em->clear();
        $user = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($user);
        $this->assertSame($newEmail, $user->getEmail());
        $this->assertSame($newUsername, $user->getUsername());
        $this->assertNull($user->getPasswordRequestedAt());
        $this->assertNull($user->getConfirmationToken());

        $emailAuditRecord = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::EmailChanged,
            'userId' => $user->getId(),
        ]);
        $this->assertInstanceOf(AuditRecord::class, $emailAuditRecord);
        $this->assertSame($oldEmail, $emailAuditRecord->attributes['email_from'] ?? null);
        $this->assertSame($newEmail, $emailAuditRecord->attributes['email_to'] ?? null);
        $this->assertSame($user->getUsernameCanonical(), $emailAuditRecord->attributes['user']['username'] ?? null);

        $usernameAuditRecord = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::UsernameChanged,
            'userId' => $user->getId(),
        ]);
        $this->assertInstanceOf(AuditRecord::class, $usernameAuditRecord);
        $this->assertSame($oldUsername, $usernameAuditRecord->attributes['username_from'] ?? null);
        $this->assertSame($user->getUsernameCanonical(), $usernameAuditRecord->attributes['username_to'] ?? null);
    }

    public function testTokenRotate(): void
    {
        $user = self::createUser();
        $this->store($user);

        $token = $user->getApiToken();
        $safeToken = $user->getSafeApiToken();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/');
        $this->assertEquals($token, $crawler->filter('.api-token')->first()->attr('data-api-token'));
        $this->assertEquals($safeToken, $crawler->filter('.api-token')->last()->attr('data-api-token'));

        $form = $crawler->selectButton('Rotate API Tokens')->form();
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $user = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($user);
        $this->assertNotEquals($token, $user->getApiToken());
        $this->assertNotEquals($safeToken, $user->getSafeApiToken());
    }
}
