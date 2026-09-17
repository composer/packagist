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

use App\Form\Model\LostTwoFactorSupportRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<LostTwoFactorSupportRequest>
 */
class LostTwoFactorSupportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('description', TextareaType::class, [
            'label' => 'Anything that helps us verify you (optional)',
            'required' => false,
            'help' => 'For example: what happened to your authenticator, or which packages you maintain.',
            'attr' => ['rows' => 5, 'maxlength' => 2000],
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => LostTwoFactorSupportRequest::class,
            'csrf_token_id' => 'lost_2fa_request',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'lost_two_factor_request';
    }
}
