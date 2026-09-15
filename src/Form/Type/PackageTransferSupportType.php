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

use App\Form\Model\PackageTransferSupportRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<PackageTransferSupportRequest>
 */
class PackageTransferSupportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('packageNames', TextareaType::class, [
                'label' => 'Which packages should be transferred?',
                'help' => 'One package per line, as vendor/name. This is the part people forget, so please be exact.',
                'attr' => ['rows' => 5, 'placeholder' => "acme/console\nacme/http-client"],
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Why should these be transferred to you?',
                'help' => 'Tell us who maintains them today, how you are related to them, and whether you have already tried to reach them.',
                'attr' => ['rows' => 6, 'maxlength' => 4000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => PackageTransferSupportRequest::class,
            'csrf_token_id' => 'package_transfer_request',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'package_transfer_request';
    }
}
