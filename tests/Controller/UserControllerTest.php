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
use App\Tests\Mock\TotpAuthenticatorStub;

class UserControllerTest extends ControllerTestCase
{
    public function testEnableTwoFactorCode(): void
    {
        $user = self::createUser();
        $this->store($user);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', sprintf('/users/%s/2fa/enable', $user->getUsername()));
        $form = $crawler->selectButton('Enable Two-Factor Authentication')->form();
        $form->setValues([
            'enable_two_factor_auth[code]' => 123456,
        ]);

        $crawler = $this->client->submit($form);
        $this->assertResponseStatusCodeSame(422);

        $form = $crawler->selectButton('Enable Two-Factor Authentication')->form();
        $form->setValues([
            'enable_two_factor_auth[code]' => TotpAuthenticatorStub::MOCKED_VALID_CODE,
        ]);

        $this->client->submit($form);
        $this->assertResponseStatusCodeSame(302);

        $em = self::getEM();
        $em->clear();
        $this->assertTrue($em->getRepository(User::class)->find($user->getId())->isTotpAuthenticationEnabled());
    }
}
