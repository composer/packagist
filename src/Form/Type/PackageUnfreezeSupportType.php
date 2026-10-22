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
use App\Form\Model\PackageUnfreezeSupportRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * @extends AbstractType<PackageUnfreezeSupportRequest>
 */
class PackageUnfreezeSupportType extends AbstractType
{
    public function __construct(private readonly TranslatorInterface $translator)
    {
    }

    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        /** @var array<string, PackageFreezeReason> $reasons */
        $reasons = $options['frozen_packages'];

        $builder
            ->add('packageNames', PackagePickerType::class, [
                'label' => 'Which packages should we look at?',
                'choices' => array_combine(array_keys($reasons), array_keys($reasons)),
                'choice_label' => fn (string $name): string => $name.' — '.$this->translator->trans($reasons[$name]->translationKey()),
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Why should the freeze be lifted?',
                'help' => 'Tell us what changed. If the repository moved, say where it moved to and how you can show you control it.',
                'attr' => ['rows' => 6, 'maxlength' => 4000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PackageUnfreezeSupportRequest::class,
            'csrf_token_id' => 'package_unfreeze_request',
        ]);
        // Keyed by package name, so the choice list and the labels come from one source.
        $resolver->setRequired('frozen_packages');
        $resolver->setAllowedTypes('frozen_packages', 'array');
    }

    public function getBlockPrefix(): string
    {
        return 'package_unfreeze_request';
    }
}
