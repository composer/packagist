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

use App\Form\Model\EnableTwoFactorRequest;
use App\Validator\TwoFactorCode;
use Scheb\TwoFactorBundle\Model\Totp\TwoFactorInterface;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class EnableTwoFactorAuthType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('code', TextType::class, [
            'constraints' => [new TwoFactorCode($options['user'])]
        ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver
            ->setDefaults([
                'data_class' => EnableTwoFactorRequest::class,
            ])
            ->define('user')
            ->allowedTypes(TwoFactorInterface::class)
            ->required();
    }
}
