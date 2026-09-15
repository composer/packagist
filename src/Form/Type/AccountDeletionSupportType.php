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

use App\Form\Model\AccountDeletionSupportRequest;
use App\Form\Model\PackageDisposition;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<AccountDeletionSupportRequest>
 */
class AccountDeletionSupportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('packageDisposition', EnumType::class, [
                'class' => PackageDisposition::class,
                'label' => 'What should happen to your packages?',
                'choice_label' => static fn (PackageDisposition $case): string => $case->label(),
                'expanded' => true,
                'placeholder' => false,
            ])
            ->add('transferTo', TextType::class, [
                'label' => 'Transfer them to which account?',
                'required' => false,
                'help' => 'The Packagist username that should take over. Only needed if you picked transfer above.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Anything else we should know? (optional)',
                'required' => false,
                'attr' => ['rows' => 5, 'maxlength' => 4000],
            ])
            ->add('acknowledged', CheckboxType::class, [
                'label' => 'I understand that deleting my account cannot be undone.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => AccountDeletionSupportRequest::class,
            'csrf_token_id' => 'account_deletion_request',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'account_deletion_request';
    }
}
