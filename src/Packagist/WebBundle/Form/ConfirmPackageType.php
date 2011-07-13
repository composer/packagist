<?php

namespace Packagist\WebBundle\Form;

use Symfony\Component\Form\FormBuilder;

class ConfirmPackageType extends PackageType
{
    public function buildForm(FormBuilder $builder, array $options)
    {
        $builder->add('repository', 'hidden');
    }
}
