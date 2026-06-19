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

use App\Form\Model\CreateOrganizationRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<CreateOrganizationRequest>
 */
class CreateOrganizationType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Display name',
                'attr' => ['maxlength' => 60, 'autofocus' => true],
                'help' => 'Letters, numbers, spaces and hyphens, up to 60 characters.',
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'attr' => ['maxlength' => 20],
                'help' => 'Used in the URL (/organizations/your-slug). Lowercase letters, numbers and hyphens, up to 20 characters.',
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => CreateOrganizationRequest::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'create_organization';
    }
}
