<?php

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
use Symfony\Component\Form\Extension\Core\Type\EmailType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ProfileFormType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder
            ->add('username', null, array('label' => 'Username'))
            ->add('email', EmailType::class, array('label' => 'Email'))
            ->addEventListener(FormEvents::PRE_SET_DATA, function(FormEvent $event) {
                if (!($user = $event->getData())) {
                    return;
                }

                if (!$user->getGithubId()) {
                    $event->getForm()->add('current_password', PasswordType::class, [
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
                    ]);
                }
            });

        $builder->add('failureNotifications', null, ['required' => false, 'label' => 'Notify me of package update failures']);
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => User::class,
            'csrf_token_id' => 'profile',
        ));
    }

    /**
     * @inheritDoc
     */
    public function getBlockPrefix()
    {
        return 'packagist_user_profile';
    }
}
