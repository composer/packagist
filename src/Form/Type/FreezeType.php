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

use App\Entity\PackageFreezeReason;
use App\Entity\UserFreezeReason;
use App\Form\Model\FreezeRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<FreezeRequest>
 */
class FreezeType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('reason', EnumType::class, [
                'class' => UserFreezeReason::class,
                'choices' => $options['account_reasons'],
                'choice_label' => static fn (UserFreezeReason $reason): string => 'user_freeze_reasons.'.$reason->value,
            ])
            ->add('reasonText', TextareaType::class, [
                'required' => false,
                'empty_data' => null,
            ])
            ->add('internalReason', TextareaType::class, [
                'required' => false,
                'empty_data' => null,
            ]);

        // The package controls only appear for moderators allowed to act on packages.
        if ($options['package_reasons'] !== []) {
            $builder
                ->add('freezePackages', CheckboxType::class, ['required' => false])
                ->add('packageFreezeReason', EnumType::class, [
                    'class' => PackageFreezeReason::class,
                    'choices' => $options['package_reasons'],
                    'choice_label' => static fn (PackageFreezeReason $reason): string => 'freezing_reasons.'.$reason->value,
                ])
                ->add('purgePackages', CheckboxType::class, ['required' => false]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FreezeRequest::class,
            'csrf_token_id' => 'freeze_user',
            'account_reasons' => UserFreezeReason::cases(),
            'package_reasons' => [PackageFreezeReason::Spam, PackageFreezeReason::Malware, PackageFreezeReason::Temporary],
        ]);
        $resolver->setAllowedTypes('account_reasons', UserFreezeReason::class.'[]');
        $resolver->setAllowedTypes('package_reasons', PackageFreezeReason::class.'[]');
    }

    public function getBlockPrefix(): string
    {
        return 'freeze';
    }
}
