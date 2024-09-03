<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Persistence\ObjectManager;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

/**
 * Creates a user to be assigned as the maintainer of packages.
 */
class UserFixtures extends Fixture implements FixtureGroupInterface
{
    public const PACKAGE_MAINTAINER = 'package-maintainer';

    public function __construct(private UserPasswordHasherInterface $passwordHasher)
    {
    }

    public static function getGroups(): array
    {
        return ['base'];
    }

    public function load(ObjectManager $manager): void
    {
        echo 'Creating users admin (password: admin), dev (password: dev), and user (password: user)'.PHP_EOL;

        $dev = new User;
        $dev->setEmail('admin@example.org');
        $dev->setUsername('admin');
        $dev->setPassword($this->passwordHasher->hashPassword($dev, 'admin'));
        $dev->setEnabled(true);
        $dev->setRoles(['ROLE_SUPERADMIN']);

        $manager->persist($dev);

        $dev = new User;
        $dev->setEmail('dev@example.org');
        $dev->setUsername('dev');
        $dev->setPassword($this->passwordHasher->hashPassword($dev, 'dev'));
        $dev->setEnabled(true);
        $dev->setRoles([]);

        $manager->persist($dev);

        $user = new User;
        $user->setEmail('user@example.org');
        $user->setUsername('user');
        $user->setPassword($this->passwordHasher->hashPassword($user, 'user'));
        $user->setEnabled(true);
        $user->setRoles([]);

        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::PACKAGE_MAINTAINER, $dev);
    }
}
