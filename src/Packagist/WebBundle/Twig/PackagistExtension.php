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

    public function getTests()
    {
        return array('packagistPackageName' => new \Twig_Test_Method($this, 'validPackageNameTest'),
            'existingPackagistPackage' => new \Twig_Test_Method($this, 'packageExistsTest'));
    }

    public function getName()
    {
        return 'packagist';
    }

    public function packageExistsTest($package)
    {
        $doctrine = $this->container->get('doctrine');
        /* @var $doctrine Symfony\Bundle\DoctrineBundle\Registry */

        return $doctrine->getRepository('PackagistWebBundle:Package')
                ->packageExists($package);
    }

    public function validPackageNameTest($package)
    {
        return preg_match('/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+/', $package);
    }
}