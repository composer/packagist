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
use App\Tests\IntegrationTestCase;

class ProfileControllerTest extends IntegrationTestCase
{
    public function testEditProfile(): void
    {
        $user = self::createUser();
        $this->store($user);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/edit');

        $form = $crawler->selectButton('Update')->form();
        $this->client->submit($form, [
            'packagist_user_profile[email]' => $newEmail = 'new-email@example.org',
        ]);

        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $user = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($user);
        $this->assertSame($newEmail, $user->getEmail());
        $this->assertNull($user->getPasswordRequestedAt());
        $this->assertNull($user->getConfirmationToken());
    }

    public function testTokenRotate(): void
    {
        $user = self::createUser();
        $this->store($user);

        $token = $user->getApiToken();
        $safeToken = $user->getSafeApiToken();

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/');
        $this->assertEquals($token, $crawler->filter('.api-token')->first()->attr('data-api-token'));
        $this->assertEquals($safeToken, $crawler->filter('.api-token')->last()->attr('data-api-token'));

        $form = $crawler->selectButton('Rotate API Tokens')->form();
        $this->client->submit($form);

        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $user = $em->getRepository(User::class)->find($user->getId());
        $this->assertNotNull($user);
        $this->assertNotEquals($token, $user->getApiToken());
        $this->assertNotEquals($safeToken, $user->getSafeApiToken());
    }
}
