<?php

namespace App\DataFixtures;

use App\Entity\User;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Persistence\ObjectManager;
use FOS\UserBundle\Util\TokenGeneratorInterface;

/**
 * Creates a user to be assigned as the maintainer of packages.
 */
class UserFixtures extends Fixture
{
    public const PACKAGE_MAINTAINER = 'package-maintainer';

    private TokenGeneratorInterface $tokenGenerator;

    public function __construct(TokenGeneratorInterface $tokenGenerator)
    {
        $this->tokenGenerator = $tokenGenerator;
    }

    public function load(ObjectManager $manager)
    {
        $user = new User;

        $user->setEmail('dev@packagist.org');
        $user->setUsername('dev');
        $user->setPlainPassword('dev');
        $user->setEnabled(true);

        $apiToken = substr($this->tokenGenerator->generateToken(), 0, 20);
        $user->setApiToken($apiToken);

        $manager->persist($user);
        $manager->flush();

        $this->addReference(self::PACKAGE_MAINTAINER, $user);
    }
}
