<?php

declare(strict_types=1);

namespace App\Entity;

use Symfony\Component\Uid\Ulid;
use Webauthn\Bundle\Repository\PublicKeyCredentialUserEntityRepository as PublicKeyCredentialUserEntityRepositoryInterface;
use Webauthn\PublicKeyCredentialUserEntity;

final class WebauthnUserEntityRepository implements PublicKeyCredentialUserEntityRepositoryInterface
{
    public function __construct(private readonly UserRepository $userRepository)
    {
    }

    public function generateNextUserEntityId(): string {
        return Ulid::generate();
    }

    public function saveUserEntity(PublicKeyCredentialUserEntity $userEntity): void
    {
        throw new \LogicException('User registration is disabled.');
    }

    public function findOneByUsername(string $username): ?PublicKeyCredentialUserEntity
    {
        /** @var User|null $user */
        $user = $this->userRepository->findOneByUsernameOrEmail($username);

        return $this->getUserEntity($user);
    }

    public function findOneByUserHandle(string $userHandle): ?PublicKeyCredentialUserEntity
    {
        /** @var User|null $user */
        $user = $this->userRepository->findOneBy([
            'usernameCanonical' => $userHandle,
        ]);

        return $this->getUserEntity($user);
    }

    private function getUserEntity(null|User $user): ?PublicKeyCredentialUserEntity
    {
        if ($user === null) {
            return null;
        }

        return new PublicKeyCredentialUserEntity(
            $user->getUsername(),
            $user->getUserIdentifier(),
            $user->getUsername(),
            null
        );
    }
}
