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

use App\Form\Model\PackageDeletionSupportRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\CheckboxType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PackageDeletionSupportRequest>
 */
class PackageDeletionSupportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $names */
        $names = $options['packages'];

        $builder
            ->add('packageNames', PackagePickerType::class, [
                'label' => 'Which packages should we delete?',
                'choices' => array_combine($names, $names),
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Why should these be deleted?',
                'help' => 'Deleting is permanent and breaks anything that still requires these packages, so tell us why removing them is the right call.',
                'attr' => ['rows' => 6, 'maxlength' => 4000],
            ])
            ->add('acknowledged', CheckboxType::class, [
                'label' => 'I understand that deleting these packages cannot be undone.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PackageDeletionSupportRequest::class,
            'csrf_token_id' => 'package_deletion_request',
        ]);
        $resolver->setRequired('packages');
        $resolver->setAllowedTypes('packages', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'package_deletion_request';
    }
}
