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

use FOS\UserBundle\Form\Type\ProfileFormType as BaseType;
use FOS\UserBundle\Util\LegacyFormHelper;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ProfileFormType extends BaseType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $this->buildUserForm($builder, $options);

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function(FormEvent $event) {
            if (!($user = $event->getData())) {
                return;
            }

            if (!$user->getGithubId()) {
                $constraintsOptions = array(
                    'message' => 'fos_user.current_password.invalid',
                );

                $event->getForm()->add('current_password', PasswordType::class, array(
                    'label' => 'form.current_password',
                    'translation_domain' => 'FOSUserBundle',
                    'mapped' => false,
                    'constraints' => array(
                        new NotBlank(),
                        new UserPassword($constraintsOptions),
                    ),
                    'attr' => array(
                        'autocomplete' => 'current-password',
                    ),
                ));
            }
        });

        $builder->add('failureNotifications', null, array('required' => false, 'label' => 'Notify me of package update failures'));
    }

    /**
     * {@inheritdoc}
     */
    public function getBlockPrefix()
    {
        return 'packagist_user_profile';
    }
}
