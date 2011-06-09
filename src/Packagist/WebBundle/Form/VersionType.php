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
use Symfony\Component\Form\Extension\Core\DataTransformer\DateTimeToStringTransformer;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class VersionType extends AbstractType
{
    public function buildForm(FormBuilder $builder, array $options)
    {
        $builder->add('description');
        $builder->add('version', null);
        $builder->add('homepage', 'url', array('required' => false));
        $builder->add('tagsText', 'text');
        $builder->add('license', null, array('required' => false));
        $builder->add('source', 'text', array('required' => false));
        $builder->add('require', null, array('required' => false));
        $builder->add('releasedAt', 'datetime', array('date_widget' => 'text', 'time_widget' => 'text'));
        $builder->add(
            $builder->create('releasedAt', 'text')
                ->appendClientTransformer(new DateTimeToStringTransformer(null, null, 'Y-m-d H:i:s'))
        );
    }

    public function getDefaultOptions(array $options)
    {
        return array(
            'data_class' => 'Packagist\WebBundle\Entity\Version',
        );
    }
}
