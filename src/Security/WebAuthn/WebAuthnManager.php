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

namespace App\Security\WebAuthn;

use App\Audit\WebAuthnCredentialAction;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\Entity\WebAuthnCredential;
use App\Event\SecondFactorsResetEvent;
use App\Event\SecondFactorRemovedEvent;
use App\Security\BackupCodeManager;
use App\Security\UserNotifier;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\EventDispatcher\Attribute\AsEventListener;
use Symfony\Component\Security\Core\Exception\BadCredentialsException;
use Symfony\Component\Serializer\SerializerInterface;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;
use Webauthn\AuthenticatorAssertionResponse;
use Webauthn\AuthenticatorAssertionResponseValidator;
use Webauthn\AuthenticatorAttestationResponse;
use Webauthn\AuthenticatorAttestationResponseValidator;
use Webauthn\Bundle\Security\Storage\Item;
use Webauthn\Bundle\Security\Storage\OptionsStorage;
use Webauthn\Bundle\Service\PublicKeyCredentialCreationOptionsFactory;
use Webauthn\Bundle\Service\PublicKeyCredentialRequestOptionsFactory;
use Webauthn\CredentialRecord;
use Webauthn\PublicKeyCredential;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;
use Webauthn\PublicKeyCredentialUserEntity;

class WebAuthnManager
{
    public function __construct(
        private readonly AuthenticatorAttestationResponseValidator $registrationValidator,
        private readonly AuthenticatorAssertionResponseValidator $assertionValidator,
        private PublicKeyCredentialCreationOptionsFactory $creationOptions,
        private PublicKeyCredentialRequestOptionsFactory $requestOptions,
        #[Autowire(service: ExpiringOptionsStorage::class)] private OptionsStorage $optionsStorage,
        private readonly SerializerInterface $serializer,
        private EntityManagerInterface $em,
        private UserNotifier $notifier,
        private EventDispatcherInterface $eventDispatcher,
        private BackupCodeManager $backupCodes,
        #[Autowire('%env(WEBAUTHN_RP_ID)%')] private readonly string $rpId,
    ) {
    }

    public function startRegistration(User $user): string
    {
        $options = $this->registrationOptions($user);
        $this->optionsStorage->store(Item::create($options, $options->user));

        return $this->serializer->serialize($options, 'json');
    }

    public function register(User $user, string $response, string $name): ?string
    {
        try {
            $key = $this->decodeCredential($response);
            $options = $this->optionsStorage->get($key->response->clientDataJSON->challenge)->getPublicKeyCredentialOptions();

            if (!$options instanceof PublicKeyCredentialCreationOptions || !hash_equals($user->getWebAuthnUserHandle(), $options->user->id)) {
                throw new \UnexpectedValueException('Registration options do not match the account.');
            }

            $record = $this->validateRegistration($key, $options);
        } catch (ExpiredWebAuthnChallengeException $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new BadCredentialsException('webauthn.error.invalid_registration', previous: $e);
        }

        $credential = new WebAuthnCredential($user, $record);

        $backupCode = $this->backupCodes->withAccountLock($user, function () use ($user, $credential, $name): ?string {
            if ($this->em->find(WebAuthnCredential::class, $credential->id) !== null) {
                throw new BadCredentialsException('webauthn.error.already_registered');
            }

            $credential->rename($name);
            $code = $user->hasSecondFactorAuthenticationEnabled() ? null : $this->backupCodes->generateBackupCode($user);
            $user->addWebAuthnCredential($credential);
            $this->em->persist($credential);
            $this->persistChange($credential, WebAuthnCredentialAction::Added);

            return $code;
        });

        $this->notifyChange($credential);

        return $backupCode;
    }

    public function startAuthentication(User $user): string
    {
        if ($user->getWebAuthnCredentials() === []) {
            throw new BadCredentialsException('webauthn.error.no_credential');
        }

        $options = $this->assertionOptions($user);
        $this->optionsStorage->store(Item::create($options, null));

        return $this->serializer->serialize($options, 'json');
    }

    public function authenticate(User $user, string $response): void
    {
        $this->backupCodes->withAccountLock($user, function () use ($user, $response): void {
            try {
                $assertion = $this->decodeCredential($response);
                $options = $this->optionsStorage->get($assertion->response->clientDataJSON->challenge)->getPublicKeyCredentialOptions();

                if (!$options instanceof PublicKeyCredentialRequestOptions) {
                    throw new \UnexpectedValueException('Expected assertion options.');
                }

                $credential = $user->findWebAuthnCredential(hash('sha256', $assertion->rawId))
                    ?? throw new BadCredentialsException('Unknown credential.');
                $this->em->refresh($credential);

                if ($credential->getRevokedAt() !== null) {
                    throw new BadCredentialsException('Unknown credential.');
                }

                $record = $this->validateAssertion($credential, $assertion, $options);
            } catch (ExpiredWebAuthnChallengeException $e) {
                throw $e;
            } catch (\Throwable $e) {
                throw new BadCredentialsException('webauthn.error.invalid_assertion', previous: $e);
            }

            $credential->recordAuthentication($record);
            $this->em->flush();
        });
    }

    public function rename(WebAuthnCredential $credential, string $name): void
    {
        $credential->rename($name);
        $this->persistChange($credential, WebAuthnCredentialAction::Renamed);
        $this->notifyChange($credential);
    }

    public function revoke(WebAuthnCredential $credential): void
    {
        $this->backupCodes->withAccountLock($credential->user, function () use ($credential): void {
            $this->stageRevocation($credential);
            $this->persistChange($credential, WebAuthnCredentialAction::Removed);
        });

        $this->notifyChange($credential);
    }

    #[AsEventListener(priority: 10)]
    public function onSecondFactorsReset(SecondFactorsResetEvent $event): void
    {
        foreach ($event->user->getWebAuthnCredentials() as $credential) {
            $this->stageRevocation($credential);
            $this->em->persist(AuditRecord::webAuthnCredentialChanged($credential, WebAuthnCredentialAction::Removed, $event->actor));
        }
    }

    private function stageRevocation(WebAuthnCredential $credential): void
    {
        $credential->revoke();
        $credential->user->invalidateAllSessions();
        $this->eventDispatcher->dispatch(new SecondFactorRemovedEvent($credential->user));
    }

    private function persistChange(WebAuthnCredential $credential, WebAuthnCredentialAction $action): void
    {
        $this->em->persist(AuditRecord::webAuthnCredentialChanged($credential, $action));
        $this->em->flush();
    }

    private function notifyChange(WebAuthnCredential $credential): void
    {
        $this->notifier->notifyChange($credential->user->getEmail(), template: 'email/webauthn_changed.txt.twig', subject: 'Passkeys and security keys changed on Packagist.org', username: $credential->user->getUsername());
    }

    private function registrationOptions(User $user): PublicKeyCredentialCreationOptions
    {
        return $this->creationOptions->create(
            'without_attestation',
            new PublicKeyCredentialUserEntity($user->getUsername(), $user->getWebAuthnUserHandle(), $user->getUsername()),
            $this->descriptors($user),
        );
    }

    private function assertionOptions(User $user): PublicKeyCredentialRequestOptions
    {
        return $this->requestOptions->create('packagist', $this->descriptors($user));
    }

    private function validateRegistration(PublicKeyCredential $key, PublicKeyCredentialCreationOptions $options): CredentialRecord
    {
        if (!$key->response instanceof AuthenticatorAttestationResponse) {
            throw new \UnexpectedValueException('Expected an attestation response.');
        }

        $record = $this->registrationValidator->check($key->response, $options, $this->rpId);

        if (!hash_equals($record->publicKeyCredentialId, $key->rawId)) {
            throw new \UnexpectedValueException('Credential IDs do not match.');
        }

        return $record;
    }

    private function validateAssertion(WebAuthnCredential $credential, PublicKeyCredential $assertion, PublicKeyCredentialRequestOptions $options): CredentialRecord
    {
        if (!$assertion->response instanceof AuthenticatorAssertionResponse) {
            throw new \UnexpectedValueException('Expected an assertion response.');
        }

        // Keep validator mutations off the managed entity until the assertion succeeds
        return $this->assertionValidator->check($credential->toCredentialRecord(), $assertion->response, $options, $this->rpId, $credential->user->getWebAuthnUserHandle());
    }

    private function decodeCredential(string $response): PublicKeyCredential
    {
        if (\strlen($response) > 65536) {
            throw new BadCredentialsException('Credential response is too large.');
        }

        return $this->serializer->deserialize($response, PublicKeyCredential::class, 'json');
    }

    /** @return list<\Webauthn\PublicKeyCredentialDescriptor> */
    private function descriptors(User $user): array
    {
        return array_map(
            static fn (WebAuthnCredential $credential) => $credential->getPublicKeyCredentialDescriptor(),
            $user->getWebAuthnCredentials(),
        );
    }
}
