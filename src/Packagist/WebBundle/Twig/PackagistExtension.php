<?php

namespace Packagist\WebBundle\Twig;

use Symfony\Component\DependencyInjection\ContainerInterface;

class PackagistExtension extends \Twig_Extension
{
    /**
     * @var ContainerInterface
     */
    private $container;
    
    public function __construct(ContainerInterface $container)
    {
        $this->container = $container;
    }
    
    public function getFunctions()
    {
        return array('packagist_package_exists' => new \Twig_Function_Method($this, 'getPackageExists'),
            'packagist_is_package_name' => new \Twig_Function_Method($this, 'validatePackageName'));
    }
    
    public function getName()
    {
        return 'packagist';
    }
    
    public function getPackageExists($package)
    {
        $doctrine = $this->container->get('doctrine');
        /* @var $doctrine Symfony\Bundle\DoctrineBundle\Registry */
        
        return $doctrine->getRepository('PackagistWebBundle:Package')
                ->packageExists($package);
    }
    
    public function validatePackageName($package)
    {
        return preg_match('/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+/', $package);
    }
}