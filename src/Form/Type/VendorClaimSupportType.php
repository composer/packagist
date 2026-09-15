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

use App\Form\Model\VendorClaimSupportRequest;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextareaType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @extends AbstractType<VendorClaimSupportRequest>
 */
class VendorClaimSupportType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder
            ->add('vendorName', TextType::class, [
                'label' => 'Which vendor name do you want?',
                'help' => 'Just the vendor part, without a package name. For example "acme", not "acme/console".',
            ])
            ->add('description', TextareaType::class, [
                'label' => 'Why should this vendor name be yours?',
                'help' => 'Anything that shows the connection: the organisation you represent, the repositories the existing packages point at, or prior contact with whoever holds it.',
                'attr' => ['rows' => 6, 'maxlength' => 4000],
            ]);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        $resolver->setDefaults([
            'data_class' => VendorClaimSupportRequest::class,
            'csrf_token_id' => 'vendor_claim_request',
        ]);
    }

    public function getBlockPrefix(): string
    {
        return 'vendor_claim_request';
    }
}
