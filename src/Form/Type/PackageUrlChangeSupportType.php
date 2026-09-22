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

use App\Form\Model\PackageUrlChangeSupportRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\ChoiceType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * packageName is a choice list over the requester's own packages rather than a text field. That
 * makes "your packages only" structural, and it also guarantees the stored name matches
 * Package::PACKAGE_NAME_REGEX -- which the admin queue relies on, since it generates an edit_package
 * URL from it and that route requires the pattern.
 *
 * @extends AbstractType<PackageUrlChangeSupportRequest>
 */
class PackageUrlChangeSupportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var list<string> $names */
        $names = $options['packages'];

        $builder
            ->add('packageName', ChoiceType::class, [
                'label' => 'Which package?',
                'choices' => array_combine($names, $names),
                'placeholder' => 'Pick a package',
            ])
            ->add('repository', TextType::class, [
                'label' => 'What should the repository URL be?',
                'help' => 'The new canonical URL, for example https://github.com/acme/console.',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'What happened to the old URL?',
                'help' => 'Tell us where the repository moved and how we can tell it is still yours, such as a commit you just pushed there.',
                'attr' => ['rows' => 6, 'maxlength' => 4000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PackageUrlChangeSupportRequest::class,
            'csrf_token_id' => 'package_url_change_request',
        ]);
        $resolver->setRequired('packages');
        $resolver->setAllowedTypes('packages', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'package_url_change_request';
    }
}
