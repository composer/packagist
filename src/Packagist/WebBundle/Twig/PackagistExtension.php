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

    public function getFilters()
    {
        return array(
            'prettify_source_reference' => new \Twig_Filter_Method($this, 'prettifySourceReference')
        );
    }

    public function getName()
    {
        return 'packagist';
    }

    public function packageExistsTest($package)
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $package)) {
            return false;
        }

        return $this->doctrine->getRepository('PackagistWebBundle:Package')
            ->packageExists($package);
    }

    public function prettifySourceReference($sourceReference)
    {
        if (preg_match('/^[a-f0-9]{40}$/', $sourceReference)) {
            return substr($sourceReference, 0, 7);
        }

        return $sourceReference;
    }
}
