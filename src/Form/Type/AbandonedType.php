<?php

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

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\Extension\Core\Type\TextType;
use Symfony\Component\Form\FormBuilderInterface;

/**
 * Class AbandonedType
 *
 * Form used to acquire replacement Package information for abandoned package.
 *
 * @package App\Form\Type
 */
class AbandonedType extends AbstractType
{
    /**
     * @param FormBuilderInterface $builder
     * @param array $options
     */
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add(
            'replacement',
            TextType::class,
            [
                'required' => false,
                'label'    => 'Replacement package',
                'attr'     => ['placeholder' => 'optional package name']
            ]
        );
    }

    /**
     * @inheritDoc
     */
    public function getBlockPrefix()
    {
        return 'package';
    }
}
