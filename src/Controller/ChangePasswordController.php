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

namespace App\Controller;

use App\Entity\User;
use App\Form\ChangePasswordFormType;
use App\Security\UserNotifier;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Http\Attribute\CurrentUser;

class ChangePasswordController extends Controller
{
    #[IsGranted('ROLE_USER')]
    #[Route(path: '/profile/change-password', name: 'change_password')]
    public function changePasswordAction(Request $request, UserPasswordHasherInterface $passwordHasher, UserNotifier $userNotifier, #[CurrentUser] User $user): Response
    {
        $form = $this->createForm(ChangePasswordFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->resetPasswordRequest();
            $user->setPassword(
                $passwordHasher->hashPassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            $userNotifier->notifyChange($user->getEmail(), 'Your password has been changed');

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('user/change_password.html.twig', [
            'form' => $form,
        ]);
    }
}
