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

use App\Entity\User;

class ProfileControllerTest extends ControllerTestCase
{
    public function testEditProfile(): void
    {
        $user = new User;
        $user->setEnabled(true);
        $user->setUsername('test');
        $user->setEmail('test@example.org');
        $user->setPassword('testtest');
        $user->setApiToken('token');
        $user->setGithubId('123456');

        $user->initializeConfirmationToken();
        $user->setPasswordRequestedAt(new \DateTime());

        $em = self::getEM();
        $em->persist($user);
        $em->flush();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/edit');

        $form = $crawler->selectButton('Update')->form();
        $this->client->submit($form, [
            'packagist_user_profile[email]' => $newEmail = 'new-email@example.org',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $em->clear();
        $user = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($user);
        $this->assertSame($newEmail, $user->getEmail());
        $this->assertNull($user->getPasswordRequestedAt());
        $this->assertNull($user->getConfirmationToken());
    }
}
