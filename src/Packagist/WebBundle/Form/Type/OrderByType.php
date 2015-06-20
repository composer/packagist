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

namespace Packagist\WebBundle\Form\Type;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolver;

/**
 * @author Benjamin Michalski <benjamin.michalski@gmail.com>
 */
class OrderByType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('sort');
        $builder->add('order', 'choice', array(
            'choices' => array(
                'asc' => 'asc',
                'desc' => 'desc'
            )
        ));
    }

    public function configureOptions(OptionsResolver $resolver)
    {
        $resolver->setDefaults(array(
            'data_class' => 'Packagist\WebBundle\Form\Model\OrderBy',
            'csrf_protection' => false,
        ));
    }


    public function getName()
    {
        return 'order_by';
    }
}
