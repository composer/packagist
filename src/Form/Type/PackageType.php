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

use App\Entity\Package;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<Package>
 */
class PackageType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('repository', TextType::class, [
            'label' => 'Repository URL (Git/Svn/Hg)',
            'attr' => [
                'placeholder' => 'e.g.: https://github.com/composer/composer',
            ],
        ]);

        if ($options['allow_maintainer_selection']) {
            $builder->add('maintainer', MaintainerType::class, [
                'property_path' => 'submittedOnBehalfOf',
                'required' => false,
                'label' => 'Assign to user',
                'help' => 'Admin only. The user who should own this package instead of you.',
                // MaintainerType resolves usernames only, despite its default placeholder
                'attr' => ['placeholder' => 'Username'],
            ]);
        }
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => Package::class,
            'validation_groups' => ['Default', 'Create'],
            // only built for users granted PackageActions::AdminSubmit, see PackageController
            'allow_maintainer_selection' => false,
        ]);
        $resolver->setAllowedTypes('allow_maintainer_selection', 'bool');
    }

    public function getBlockPrefix(): string
    {
        return 'package';
    }
}
