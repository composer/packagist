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

namespace App\Tests\Security\WebAuthn;

use App\Entity\User;
use App\Entity\WebAuthnCredential;
use App\Security\WebAuthn\WebAuthnManager;
use App\Security\UserNotifier;
use Doctrine\ORM\EntityManagerInterface;
use PHPUnit\Framework\Attributes\TestWith;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\HttpFoundation\Session\Session;
use Symfony\Component\HttpFoundation\Session\Storage\MockArraySessionStorage;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Webauthn\Bundle\Security\Storage\SessionStorage;

class WebAuthnManagerTest extends TestCase
{
    private User $user;
    private WebAuthnManager $manager;
    private VirtualAuthenticator $authenticator;

    protected function setUp(): void
    {
        $this->user = new User();
        $this->user->setUsername('alice');
        $this->user->setEmail('alice@example.org');
        new \ReflectionProperty(User::class, 'id')->setValue($this->user, 123);
        $request = new Request();
        $request->setSession(new Session(new MockArraySessionStorage()));
        $requests = new RequestStack();
        $requests->push($request);
        $this->manager = $this->createManager($requests);
        $this->authenticator = new VirtualAuthenticator();
    }

    public function testSignInCanRetryWhenTheAccountLockIsBusy(): void
    {
        $manager = $this->createStub(WebAuthnManager::class);
        $manager->method('authenticate')->willThrowException(
            new \Symfony\Component\HttpKernel\Exception\ServiceUnavailableHttpException(1, 'Account security settings are being updated. Please try again.'),
        );
        $provider = new \App\Security\WebAuthn\WebAuthnTwoFactorProvider($manager, new \Twig\Environment(new \Twig\Loader\ArrayLoader()));

        $this->expectException(\Symfony\Component\Security\Core\Exception\CustomUserMessageAuthenticationException::class);
        $this->expectExceptionMessage('Please try again.');
        $provider->validateAuthenticationCode($this->user, 'response');
    }

    #[TestWith(['origin'])]
    #[TestWith(['rpId'])]
    #[TestWith(['challenge'])]
    #[TestWith(['presence'])]
    #[TestWith(['malformed'])]
    #[TestWith(['signature'])]
    public function testRegistrationRejectsInvalidResponse(string $failure): void
    {
        $options = json_decode($this->manager->startRegistration($this->user), true);
        match ($failure) {
            'origin' => $this->authenticator->origin = 'https://attacker.test',
            'rpId' => $this->authenticator->rpId = 'attacker.test',
            'challenge' => $options['challenge'] = 'AAAAAAAA',
            'presence' => $this->authenticator->flags = 0,
            'signature' => $this->authenticator->corruptSignature = true,
            default => null,
        };
        $response = $failure === 'malformed' ? '{' : $this->authenticator->register($options, $failure === 'signature');
        $this->expectException(BadCredentialsException::class);
        $this->manager->register($this->user, $response, 'Key');
    }

    #[TestWith(['origin'])]
    #[TestWith(['rpId'])]
    #[TestWith(['challenge'])]
    #[TestWith(['presence'])]
    #[TestWith(['signature'])]
    #[TestWith(['revoked'])]
    public function testAuthenticationRejectsInvalidResponse(string $failure): void
    {
        $credential = $this->register();
        $options = json_decode($this->manager->startAuthentication($this->user), true);
        match ($failure) {
            'origin' => $this->authenticator->origin = 'https://attacker.test',
            'rpId' => $this->authenticator->rpId = 'attacker.test',
            'challenge' => $options['challenge'] = 'AAAAAAAA',
            'presence' => $this->authenticator->flags = 0,
            'revoked' => $credential->revoke(),
            default => null,
        };
        $response = $this->authenticator->authenticate($options);

        if ($failure === 'signature') {
            $contents = json_decode($response, true);
            $contents['response']['signature'] = base64_encode(random_bytes(64));
            $response = json_encode($contents, \JSON_THROW_ON_ERROR);
        }
        $this->expectException(BadCredentialsException::class);
        $this->manager->authenticate($this->user, $response);
    }

    public function testRegistrationChallengeCannotBeUsedForAnotherAccount(): void
    {
        $options = json_decode($this->manager->startRegistration($this->user), true);
        $other = new User();

        $this->expectException(BadCredentialsException::class);
        $this->manager->register($other, $this->authenticator->register($options), 'Key');
    }

    public function testChallengeCannotBeReplayed(): void
    {
        $this->register();
        $options = json_decode($this->manager->startAuthentication($this->user), true);
        $response = $this->authenticator->authenticate($options);
        $this->manager->authenticate($this->user, $response);
        $this->expectException(BadCredentialsException::class);
        $this->manager->authenticate($this->user, $response);
    }

    private function createManager(RequestStack $requests): WebAuthnManager
    {
        $algorithms = new \Cose\Algorithm\Manager()->add(new \Cose\Algorithm\Signature\ECDSA\ES256());
        $support = new \Webauthn\AttestationStatement\AttestationStatementSupportManager([
            new \Webauthn\AttestationStatement\PackedAttestationStatementSupport($algorithms),
        ]);
        $factory = new \Webauthn\CeremonyStep\CeremonyStepManagerFactory();
        $factory->setAllowedOrigins(['https://packagist.wip']);
        $factory->setAttestationStatementSupportManager($support);

        $em = $this->createStub(EntityManagerInterface::class);
        $events = new \Symfony\Component\EventDispatcher\EventDispatcher();
        $notifier = $this->createStub(UserNotifier::class);
        $locker = $this->createStub(\App\Service\Locker::class);
        $locker->method('lockAccountSecurity')->willReturn(true);

        return new WebAuthnManager(
            new \Webauthn\AuthenticatorAttestationResponseValidator($factory->creationCeremony()),
            new \Webauthn\AuthenticatorAssertionResponseValidator($factory->requestCeremony()),
            new \Webauthn\Bundle\Service\PublicKeyCredentialCreationOptionsFactory([
                'without_attestation' => [
                    'rp' => ['id' => 'packagist.wip', 'name' => ''],
                    'challenge_length' => 32,
                    'timeout' => 60000,
                    'attestation_conveyance' => 'none',
                    'public_key_credential_parameters' => [-7, -257],
                    'authenticator_selection_criteria' => ['authenticator_attachment' => null, 'user_verification' => 'preferred', 'resident_key' => 'preferred'],
                    'extensions' => [],
                ],
            ]),
            new \Webauthn\Bundle\Service\PublicKeyCredentialRequestOptionsFactory([
                'packagist' => [
                    'rp_id' => 'packagist.wip',
                    'challenge_length' => 32,
                    'timeout' => 60000,
                    'user_verification' => 'preferred',
                    'extensions' => [],
                ],
            ]),
            new SessionStorage($requests),
            (new \Webauthn\Denormalizer\WebauthnSerializerFactory($support))->create(),
            $em,
            $notifier,
            $events,
            new \App\Security\BackupCodeManager($events, $em, $requests, $notifier, $locker),
            'packagist.wip',
        );
    }

    /** @param array<string, mixed>|null $options */
    private function register(?array $options = null): WebAuthnCredential
    {
        $options ??= json_decode($this->manager->startRegistration($this->user), true);
        $this->manager->register($this->user, $this->authenticator->register($options), 'My key');
        $credential = $this->user->findWebAuthnCredential(hash('sha256', $this->authenticator->id));
        self::assertNotNull($credential);

        return $credential;
    }
}
