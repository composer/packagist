<?php declare(strict_types=1);

namespace App\Controller;

use App\Form\ChangePasswordFormType;
use App\Form\Type\ProfileFormType;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Encoder\UserPasswordEncoderInterface;
use Symfony\Component\Security\Http\Authentication\AuthenticationUtils;

class ChangePasswordController extends Controller
{
    /**
     * @Route("/profile/change-password", name="change_password")
     */
    public function changePasswordAction(Request $request, UserPasswordEncoderInterface $passwordEncoder)
    {
        $user = $this->getUser();
        if (!is_object($user)) {
            throw $this->createAccessDeniedException('This user does not have access to this section.');
        }

        $form = $this->createForm(ChangePasswordFormType::class, $user);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $user->setPassword(
                $passwordEncoder->encodePassword(
                    $user,
                    $form->get('plainPassword')->getData()
                )
            );

            $this->getEM()->persist($user);
            $this->getEM()->flush();

            return $this->redirectToRoute('my_profile');
        }

        return $this->render('user/change_password.html.twig', array(
            'form' => $form->createView(),
        ));
    }
}
