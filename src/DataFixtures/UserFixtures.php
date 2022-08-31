<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a user to be assigned as the maintainer of packages.
 */
class UserFixtures extends Fixture
{
    public const PACKAGE_MAINTAINER = 'package-maintainer';

    public function __construct(private UserPasswordHasherInterface $passwordHasher)
    {
    }

    public function load(ObjectManager $manager): void
    {
        $dev = new User;
        $dev->setEmail('dev@example.org');
        $dev->setUsername('dev');
        $dev->setPassword($this->passwordHasher->hashPassword($dev, 'dev'));
        $dev->setEnabled(true);
        $dev->setRoles(['ROLE_SUPERADMIN']);
        $dev->initializeApiToken();

        $manager->persist($dev);

        $user = new User;
        $user->setEmail('dev@example.org');
        $user->setUsername('user');
        $user->setPassword($this->passwordHasher->hashPassword($user, 'user'));
        $user->setEnabled(true);
        $user->setRoles([]);
        $user->initializeApiToken();

        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::PACKAGE_MAINTAINER, $dev);
    }
}
