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

namespace App\Form\Type;

use App\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * @extends AbstractType<User>
 */
class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('username', TextType::class, ['label' => 'Username', 'required' => true])
            ->add('email', EmailType::class, ['label' => 'Email'])
            ->addEventListener(FormEvents::PRE_SET_DATA, static function (FormEvent $event) {
                if (!($user = $event->getData())) {
                    return;
                }

                if (!$user->getGithubId()) {
                    $event->getForm()
                        ->add('current_password', PasswordType::class, [
                            'label' => 'Current password',
                            'translation_domain' => 'FOSUserBundle',
                            'mapped' => false,
                            'constraints' => [
                                new NotBlank(),
                                new UserPassword(),
                            ],
                            'attr' => [
                                'autocomplete' => 'current-password',
                            ],
                        ])
                        ->add('captcha', InvisibleRecaptchaType::class, ['only_show_after_increment_trigger' => true]);
                }
            });

        $builder
            ->add('failureNotifications', CheckboxType::class, ['required' => false, 'label' => 'Notify me of package update failures']);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => User::class,
            'csrf_token_id' => 'profile',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'packagist_user_profile';
    }
}
