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

use App\Form\Model\OrganizationDetailsRequest;
use App\Organization\Domain\DisplayName;
use App\Organization\Domain\Slug;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<OrganizationDetailsRequest>
 */
class OrganizationDetailsType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $slugHelp = sprintf('Used in the URL (/organizations/your-slug). Lowercase letters, numbers and hyphens, up to %s characters.', Slug::MAX_LENGTH);
        if ($options['include_rename_notice']) {
            $slugHelp .= ' Changing it reserves the old slug so it cannot be reused.';
        }

        $builder
            ->add('displayName', TextType::class, [
                'label' => 'Display name',
                'attr' => ['maxlength' => DisplayName::MAX_LENGTH, 'autofocus' => true],
                'help' => sprintf('Letters, numbers, spaces and hyphens, up to %d characters.', DisplayName::MAX_LENGTH),
            ])
            ->add('slug', TextType::class, [
                'label' => 'Slug',
                'attr' => ['maxlength' => Slug::MAX_LENGTH],
                'help' => $slugHelp,
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => OrganizationDetailsRequest::class,
            'include_rename_notice' => false,
        ]);
        $resolver->setAllowedTypes('include_rename_notice', 'bool');
    }
}
