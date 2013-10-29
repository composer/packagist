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

namespace Packagist\WebBundle\Form\Type;

use FOS\UserBundle\Form\Type\ProfileFormType as BaseType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\Form\FormEvents;
use Symfony\Component\Form\FormEvent;
use Symfony\Component\Security\Core\SecurityContext;
use Symfony\Component\Security\Core\Validator\Constraint\UserPassword as OldUserPassword;
use Symfony\Component\Security\Core\Validator\Constraints\UserPassword;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ProfileFormType extends BaseType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('username', null, array('label' => 'form.username', 'translation_domain' => 'FOSUserBundle'))
                ->add('email', 'email', array('label' => 'form.email', 'translation_domain' => 'FOSUserBundle'));

        $builder->addEventListener(FormEvents::PRE_SET_DATA, function(FormEvent $event)
        {
            if ( ! ($user = $event->getData())) return;

            if ( ! $user->getGithubId()) {
                $this->addPasswordField($event);
            }
        });

        $builder->add('failureNotifications', null, array('required' => false, 'label' => 'Notify me of package update failures'));
    }

    private function addPasswordField($event)
    {
        $event->getForm()->add('current_password', 'password', array(
            'label' => 'form.current_password',
            'translation_domain' => 'FOSUserBundle',
            'mapped' => false,
            'constraints' => new UserPassword(),
        ));
    }

    public function getName()
    {
        return 'packagist_user_profile';
    }
}
