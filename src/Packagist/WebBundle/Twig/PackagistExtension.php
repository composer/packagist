<?php

namespace Packagist\WebBundle\Twig;

use Symfony\Bridge\Doctrine\RegistryInterface;

class PackagistExtension extends \Twig_Extension
{
    /**
     * @var \Symfony\Bridge\Doctrine\RegistryInterface
     */
    private $doctrine;

    public function __construct(RegistryInterface $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    public function getTests()
    {
        return array(
            'existing_package' => new \Twig_Test_Method($this, 'packageExistsTest')
        );
    }

    public function getName()
    {
        return 'packagist';
    }

    public function packageExistsTest($package)
    {
        if (!preg_match('/[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+/', $package)) {
            return false;
        }

        return $this->doctrine->getRepository('PackagistWebBundle:Package')
            ->packageExists($package);
    }
}
