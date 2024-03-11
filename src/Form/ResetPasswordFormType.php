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

namespace App\Form;

use App\Entity\User;
use App\Validator\Password;
use App\Validator\RateLimitingRecaptcha;
use App\Validator\TwoFactorCode;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\PasswordType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

class ResetPasswordFormType extends AbstractType
{
    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->define('user')
            ->allowedTypes(User::class)
            ->required();
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('plainPassword', PasswordType::class, [
                // instead of being set onto the object directly,
                // this is read and encoded in the controller
                'label' => 'New password',
                'mapped' => false,
                'constraints' => [
                    new Password(),
                ],
            ])
        ;

        if ($options['user']->isTotpAuthenticationEnabled()) {
            $builder
                ->add('twoFactorCode', TextType::class, [
                    'label' => 'Two-Factor Code',
                    'required' => true,
                    'mapped' => false,
                    'constraints' => [
                        new RateLimitingRecaptcha(),
                        new TwoFactorCode($options['user']),
                    ],
                ]);
        }
    }
}
