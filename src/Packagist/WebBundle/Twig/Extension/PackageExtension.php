<?php

namespace Packagist\WebBundle\Twig\Extension;

class PackageExtension extends \Twig_Extension
{

    /**
     * @{inheritDoc}
     */
    public function getFilters()
    {
        return array(
            'match_name' => new \Twig_Filter_Method($this, 'match'),
        );
    }

    /**
     * Match if the value is a package name
     *
     * @param  string $value
     * @return Boolean
     */
    public function match($value)
    {
        return (boolean) preg_match('{^[a-z0-9_.-]+/[a-z0-9_.-]+$}i', $value);
    }

    /**
     * @{inheritDoc}
     */
    public function getName()
    {
        return 'package_extension';
    }
}
