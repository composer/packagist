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
use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\Tests\IntegrationTestCase;
use App\Validator\NotProhibitedPassword;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\Clock\Clock;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\Clock\NativeClock;

class RegistrationControllerTest extends IntegrationTestCase
{
    public function testRegisterWithoutOAuth(): void
    {
        $crawler = $this->client->request('GET', '/register/');
        $this->assertResponseStatusCodeSame(200);

        $form = $crawler->filter('[name="registration_form"]')->form();
        $form->setValues([
            'registration_form[email]' => 'Max@Example.com',
            'registration_form[username]' => 'max.example',
            'registration_form[plainPassword]' => 'SuperSecret123',
        ]);

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        // Should redirect to check-email page
        $this->assertResponseRedirects();
        $redirectUrl = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith('/register/check-email/', $redirectUrl);

        $em = self::getEM();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'max.example']);
        $this->assertInstanceOf(User::class, $user);
        $this->assertSame('max@example.com', $user->getEmailCanonical(), 'user email should have been canonicalized');
        $this->assertFalse($user->isEnabled(), 'user should not be enabled yet');

        $log = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::UserCreated,
            'userId' => $user->getId(),
        ]);
        $this->assertInstanceOf(AuditRecord::class, $log);
        $this->assertSame($user->getUsernameCanonical(), $log->attributes['username'] ?? null);
        $this->assertSame(UserRegistrationMethod::REGISTRATION_FORM->value, $log->attributes['method'] ?? null);
    }

    #[TestWith(['max.example'])]
    #[TestWith(['max@example.com'])]
    #[TestWith(['Max@Example.com'])]
    public function testRegisterWithTooSimplePasswords(string $password): void
    {
        $crawler = $this->client->request('GET', '/register/');
        $this->assertResponseStatusCodeSame(200);

        $form = $crawler->filter('[name="registration_form"]')->form();
        $form->setValues([
            'registration_form[email]' => 'Max@Example.com',
            'registration_form[username]' => 'max.example',
            'registration_form[plainPassword]' => $password,
        ]);

        $crawler = $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422, 'Should be invalid because password is the same as email or username');

        $this->assertFormError(new NotProhibitedPassword()->message, 'registration_form', $crawler);
    }

    public function testCheckEmailPageDisplaysCorrectly(): void
    {
        $token = $this->registerUserAndGetToken('test@example.com', 'test.user');

        $crawler = $this->client->request('GET', '/register/check-email/' . $token);
        $this->assertResponseStatusCodeSame(200);

        $this->assertStringContainsString('test@example.com', $crawler->filter('body')->text());
        $this->assertCount(1, $crawler->filter('form[action*="/register/resend/"]'));
        $this->assertCount(1, $crawler->filter('input[name="update_email_form[email]"]'));
    }

    public function testEmailUpdateAndResend(): void
    {
        $token = $this->registerUserAndGetToken('old@example.com', 'test.user2');

        $crawler = $this->client->request('GET', '/register/check-email/' . $token);
        $this->assertResponseStatusCodeSame(200);

        $form = $crawler->selectButton('Update & Resend Confirmation Email')->form();
        $form->setValues([
            'update_email_form[email]' => 'new@example.com',
        ]);

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        // Verify email was updated
        $em = self::getEM();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test.user2']);
        $this->assertNotNull($user);
        $this->assertSame('new@example.com', $user->getEmail());

        // Should redirect to check-email with new token
        $this->assertResponseRedirects();
        $redirectUrl = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith('/register/check-email/', $redirectUrl);

        $log = $em->getRepository(AuditRecord::class)->findOneBy([
            'type' => AuditRecordType::EmailChanged,
            'userId' => $user->getId(),
        ]);
        $this->assertInstanceOf(AuditRecord::class, $log);
        $this->assertSame($user->getUsernameCanonical(), $log->attributes['user']['username'] ?? null);
        $this->assertSame('old@example.com', $log->attributes['email_from'] ?? null);
        $this->assertSame('new@example.com', $log->attributes['email_to'] ?? null);
    }

    public function testEmailUpdateDoesNotCreateAuditRecordIfEmailIsUnchanged(): void
    {
        $token = $this->registerUserAndGetToken('old@example.com', 'test.user2');

        $crawler = $this->client->request('GET', '/register/check-email/' . $token);
        $this->assertResponseStatusCodeSame(200);

        $form = $crawler->selectButton('Update & Resend Confirmation Email')->form();
        $form->setValues([
            'update_email_form[email]' => 'old@example.com',
        ]);

        $this->client->submit($form);

        $log = self::getEM()->getRepository(AuditRecord::class)->findOneBy(['type' => AuditRecordType::EmailChanged]);
        $this->assertNull($log);
    }

    public function testInvalidEmailRejected(): void
    {
        $token = $this->registerUserAndGetToken('test@example.com', 'test.user3');

        $crawler = $this->client->request('GET', '/register/check-email/' . $token);
        $form = $crawler->selectButton('Update & Resend Confirmation Email')->form();
        $form->setValues([
            'update_email_form[email]' => 'invalid-email',
        ]);

        $crawler = $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422);

        // Should display validation error
        $this->assertCount(1, $crawler->filter('.alert-danger'));
    }

    public function testExpiredTokenRejected(): void
    {
        Clock::set($mockClock = new MockClock());

        $token = $this->registerUserAndGetToken('test@example.com', 'test.user4');

        $mockClock->sleep(11*60);

        $this->client->request('GET', '/register/check-email/' . $token);
        $this->assertResponseStatusCodeSame(302);
        $this->assertResponseRedirects('/register/');

        Clock::set(new NativeClock());
    }

    public function testInvalidTokenRejected(): void
    {
        $this->client->request('GET', '/register/check-email/invalid-token');
        $this->assertResponseStatusCodeSame(302);
        $this->assertResponseRedirects('/register/');
    }

    public function testEnabledUserCannotUseToken(): void
    {
        $token = $this->registerUserAndGetToken('test@example.com', 'test.user5');

        // Enable the user
        $em = self::getEM();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test.user5']);
        $user->setEnabled(true);
        $em->flush();

        $this->client->request('GET', '/register/check-email/' . $token);
        $this->assertResponseStatusCodeSame(302);
        $this->assertResponseRedirects('/register/');
    }

    public function testTamperedTokenRejected(): void
    {
        $token = $this->registerUserAndGetToken('test@example.com', 'test.user6');

        // Tamper with the token by changing a character
        $tamperedToken = substr($token, 0, -5) . 'XXXXX';

        $this->client->request('GET', '/register/check-email/' . $tamperedToken);
        $this->assertResponseStatusCodeSame(302);
        $this->assertResponseRedirects('/register/');
    }

    public function testEmailConfirmationLinkInvalidatedAfterEmailUpdate(): void
    {
        $this->client->enableProfiler();

        // Register with email A
        $crawler = $this->client->request('GET', '/register/');
        $form = $crawler->filter('[name="registration_form"]')->form();
        $form->setValues([
            'registration_form[email]' => 'emailA@example.com',
            'registration_form[username]' => 'test.user7',
            'registration_form[plainPassword]' => 'SuperSecret123',
        ]);

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        // Capture the confirmation email sent to email A
        $this->assertEmailCount(1);
        $emailA = $this->getMailerMessage();
        $this->assertNotNull($emailA);
        $emailABody = $emailA->getTextBody();

        // Extract the verification URL from email A
        preg_match('/http[s]?:\/\/[^\s]+\/register\/verify[^\s]+/', $emailABody, $matches);
        $this->assertNotEmpty($matches, 'Should find verification URL in email A');
        $originalVerificationUrl = $matches[0];

        // Get the check-email token
        $redirectUrl = $this->client->getResponse()->headers->get('Location');
        $token = substr($redirectUrl, strlen('/register/check-email/'));

        // Re-enable profiler for next request
        $this->client->enableProfiler();

        // Update email to B and resend
        $crawler = $this->client->request('GET', '/register/check-email/' . $token);
        $form = $crawler->selectButton('Update & Resend Confirmation Email')->form();
        $form->setValues([
            'update_email_form[email]' => 'emailB@example.com',
        ]);

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        // Capture the new confirmation email sent to email B
        $this->assertEmailCount(1); // One email sent in this request
        $emailB = $this->getMailerMessage();
        $this->assertNotNull($emailB);
        $emailBBody = $emailB->getTextBody();

        // Extract the verification URL from email B
        preg_match('/http[s]?:\/\/[^\s]+\/register\/verify[^\s]+/', $emailBBody, $matches);
        $this->assertNotEmpty($matches, 'Should find verification URL in email B');
        $newVerificationUrl = $matches[0];

        // Verify email was updated and user is still disabled
        $em = self::getEM();
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test.user7']);
        $this->assertSame('emailB@example.com', $user->getEmail());
        $this->assertFalse($user->isEnabled(), 'User should still be disabled after email update');

        // Try to activate with the ORIGINAL link for email A (should fail)
        $urlParts = parse_url($originalVerificationUrl);
        $verificationPathA = $urlParts['path'] . '?' . $urlParts['query'];

        $this->client->request('GET', $verificationPathA);

        // Should redirect with an error (link is no longer valid because email changed)
        $this->assertResponseRedirects('/register/');

        // Verify user is STILL disabled (proving link A didn't work)
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test.user7']);
        $this->assertFalse($user->isEnabled(), 'User should still be disabled after trying email A link');

        // Now activate with the NEW email B's verification link (should succeed)
        $urlParts = parse_url($newVerificationUrl);
        $verificationPathB = $urlParts['path'] . '?' . $urlParts['query'];

        $this->client->request('GET', $verificationPathB);
        $this->assertResponseStatusCodeSame(302);
        $this->assertResponseRedirects('/');

        // Verify user is now enabled
        $em->clear();
        $user = $em->getRepository(User::class)->findOneBy(['username' => 'test.user7']);
        $this->assertTrue($user->isEnabled(), 'User should be enabled after confirming email B');
    }

    private function registerUserAndGetToken(string $email, string $username): string
    {
        $crawler = $this->client->request('GET', '/register/');
        $form = $crawler->filter('[name="registration_form"]')->form();
        $form->setValues([
            'registration_form[email]' => $email,
            'registration_form[username]' => $username,
            'registration_form[plainPassword]' => 'SuperSecret123',
        ]);

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $redirectUrl = $this->client->getResponse()->headers->get('Location');
        $this->assertStringStartsWith('/register/check-email/', $redirectUrl);

        // Extract token from redirect URL
        return substr($redirectUrl, strlen('/register/check-email/'));
    }
}
