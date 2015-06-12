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

use Doctrine\ORM\EntityRepository;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\User;
use Symfony\Component\Form\AbstractType;
use Symfony\Component\Form\FormBuilderInterface;
use Symfony\Component\OptionsResolver\OptionsResolverInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RemoveMaintainerRequestType extends AbstractType
{
    public function buildForm(FormBuilderInterface $builder, array $options)
    {
        $builder->add('user', 'entity', array(
            'class' => 'PackagistWebBundle:User',
            'query_builder' => function(EntityRepository $er) use ($options) {
                return $er->getPackageMaintainersQueryBuilder($options['package'], $options['excludeUser']);
            },
        ));
    }

    public function setDefaultOptions(OptionsResolverInterface $resolver)
    {
        $resolver->setRequired(array('package'));
        $resolver->setDefaults(array(
            'excludeUser' => null,
            'data_class' => 'Packagist\WebBundle\Form\Model\MaintainerRequest'
        ));
    }

    public function getName()
    {
        return 'remove_maintainer_form';
    }
}
