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

use App\Form\Model\AdminCreateOrganizationRequest;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * Admin-only organization creation form: the shared display name / slug fields from
 * {@see OrganizationDetailsType} plus an owner picker.
 */
class AdminCreateOrganizationType extends OrganizationDetailsType
{
    public function buildForm(FormBuilderInterface $builder, array $options): void
    {
        $builder->add('owner', TextType::class, [
            'label' => 'Owner',
            'help' => 'Username or email of the user who becomes the first owner. They must have two-factor authentication enabled.',
        ]);

        parent::buildForm($builder, $options);
    }

    public function configureOptions(OptionsResolver $resolver): void
    {
        parent::configureOptions($resolver);

        $resolver->setDefault('data_class', AdminCreateOrganizationRequest::class);
    }
}
