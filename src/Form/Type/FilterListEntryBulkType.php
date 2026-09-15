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
use App\FilterList\FilterSources;
use App\Form\Model\FilterListEntryBulkRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\EnumType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\UrlType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<FilterListEntryBulkRequest>
 */
class FilterListEntryBulkType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('list', EnumType::class, [
                'class' => FilterLists::class,
                'choice_label' => fn (FilterLists $list) => $list->value,
                'label' => 'List',
            ])
            ->add('source', EnumType::class, [
                'class' => FilterSources::class,
                'choice_label' => fn (FilterSources $source) => $source->displayName(),
                'label' => 'Source',
                // Manually added entries are always ours, so this is display-only.
                'disabled' => true,
                'data' => FilterSources::PACKAGIST,
                'mapped' => false,
            ])
            ->add('packages', TextareaType::class, [
                'label' => 'Packages',
                'attr' => ['rows' => 12],
                'help' => 'One entry per line as "vendor/name <constraint>", e.g. "acme/foo 1.2.3" or "acme/bar >=1.0 <2.0". The fields below apply to every line.',
            ])
            ->add('reason', TextareaType::class, [
                'label' => 'Reason',
                'required' => false,
            ])
            ->add('link', UrlType::class, [
                'label' => 'External reference URL',
                'required' => false,
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
            'data_class' => FilterListEntryBulkRequest::class,
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'filter_list_entry_bulk';
    }
}
