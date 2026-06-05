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

use App\FilterList\FilterLists;
use App\Form\Model\FilterListEntryRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<FilterListEntryRequest>
 */
class FilterListEntryType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('packageName', TextType::class, [
                'label' => 'Package name',
                'disabled' => true,
            ])
            ->add('list', EnumType::class, [
                'class' => FilterLists::class,
                'choice_label' => fn (FilterLists $list) => $list->value,
                'label' => 'List',
                'disabled' => true,
            ])
            ->add('version', TextType::class, [
                'label' => 'Version constraint',
                'help' => 'A Composer version constraint, e.g. "1.2.3", ">=1.0 <2.0" or "*".',
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Reason',
                'required' => false,
                'disabled' => true,
            ])
            ->add('link', UrlType::class, [
                'label' => 'External reference URL',
                'required' => false,
                'disabled' => true,
            ])
            ->add('internalNote', TextareaType::class, [
                'label' => 'Internal note',
                'help' => 'Only recorded internally. Changes are written to the audit log and visible to filter list admins only.',
                'required' => false,
            ])
        ;
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => FilterListEntryRequest::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'filter_list_entry';
    }
}
