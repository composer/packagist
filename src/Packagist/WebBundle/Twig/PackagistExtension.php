<?php

namespace Packagist\WebBundle\Twig;

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;

class PackagistExtension extends \Twig_Extension
{
    /**
     * @var Symfony\Bridge\Doctrine\RegistryInterface
     */
    private $doctrine;

    public function setDoctrine(RegistryInterface $doctrine)
    {
        $this->doctrine = $doctrine;
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
        return $this->doctrine->getRepository('PackagistWebBundle:Package')
                ->packageExists($package);
    }

    public function validPackageNameTest($package)
    {
        return preg_match('/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+/', $package);
    }
}