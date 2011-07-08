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

namespace Packagist\WebBundle\Form;

use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilder;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ConfirmFormType extends AbstractType
{
    public function buildForm(FormBuilder $builder, array $options)
    {
    }

    public function getDefaultOptions(array $options)
    {
        return array(
            'data_class' => 'Packagist\WebBundle\Form\ConfirmForm',
        );
    }
}
