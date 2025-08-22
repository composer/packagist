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
use App\Validator\NotProhibitedPassword;
use PHPUnit\Framework\Attributes\TestWith;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;

class ChangePasswordControllerTest extends ControllerTestCase
{
    #[TestWith(['SuperSecret123', 'ok'])]
    #[TestWith(['test@example.org', 'prohibited-password-error'])]
    public function testChangePassword(string $newPassword, string $expectedResult): void
    {
        $user = self::createUser();

        $currentPassword = 'current-one-123';
        $currentPasswordHash = self::getContainer()->get(UserPasswordHasherInterface::class)->hashPassword($user, $currentPassword);
        $user->setPassword($currentPasswordHash);

        $this->store($user);

        $this->client->loginUser($user);

        $crawler = $this->client->request('GET', '/profile/change-password');

        $form = $crawler->selectButton('Change password')->form();
        $crawler = $this->client->submit($form, [
            'change_password_form[current_password]' => $currentPassword,
            'change_password_form[plainPassword]' => $newPassword,
        ]);

        if ($expectedResult == 'ok') {
            $this->assertResponseStatusCodeSame(302);

            $em = self::getEM();
            $em->clear();
            $user = $em->getRepository(User::class)->find($user->getId());
            $this->assertNotNull($user);
            $this->assertNotSame($currentPasswordHash, $user->getPassword());
        }

        if ($expectedResult === 'prohibited-password-error') {
            $this->assertResponseStatusCodeSame(422);
            $this->assertFormError(new NotProhibitedPassword()->message, 'change_password_form', $crawler);
        }
    }
}
