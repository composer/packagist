<?php

namespace App\Tests\Fixtures;

use App\Entity\Package;
use App\Entity\User;

trait Fixtures
{
    /**
     * Creates a Package entity without running the slow network-based repository initialization step
     *
     * @param array<User> $maintainers
     */
    protected static function createPackage(string $name, string $repository, ?string $remoteId = null, array $maintainers = []): Package
    {
        $package = new Package();

        $package->setName($name);
        $package->setRemoteId($remoteId);
        new \ReflectionProperty($package, 'repository')->setValue($package, $repository);
        if (\count($maintainers) > 0) {
            foreach ($maintainers as $user) {
                $package->addMaintainer($user);
                $user->addPackage($package);
            }
        }

        return $package;
    }

    /**
     * @param array<string> $roles
     */
    protected static function createUser(string $username = 'test', string $email = 'test@example.org', string $password = 'testtest', string $apiToken = 'api-token', string $safeApiToken = 'safe-api-token', string $githubId = '12345', bool $enabled = true, array $roles = []): User
    {
        $user = new User();
        $user->setEnabled($enabled);
        $user->setUsername($username);
        $user->setEmail($email);
        $user->setPassword($password);
        $user->setApiToken($apiToken);
        $user->setSafeApiToken($safeApiToken);
        $user->setGithubId($githubId);
        $user->setRoles($roles);

        return $user;
    }
}
