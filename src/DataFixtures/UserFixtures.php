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

    public function load(ObjectManager $manager)
    {
        $user = new User;

        $user->setEmail('dev@packagist.org');
        $user->setUsername('dev');
        $user->setPassword($this->passwordHasher->hashPassword($user, 'dev'));
        $user->setEnabled(true);

        $user->initializeApiToken();

        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::PACKAGE_MAINTAINER, $user);
    }
}
