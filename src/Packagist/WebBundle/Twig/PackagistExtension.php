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
            new \Twig_SimpleTest('existing_package', [$this, 'packageExistsTest']),
            new \Twig_SimpleTest('existing_provider', [$this, 'providerExistsTest']),
            new \Twig_SimpleTest('numeric', [$this, 'numericTest']),
        );
    }

    public function getFilters()
    {
        return array(
            new \Twig_SimpleFilter('prettify_source_reference', [$this, 'prettifySourceReference']),
            new \Twig_SimpleFilter('gravatar_hash', [$this, 'generateGravatarHash'])
        );
    }

    public function getName()
    {
        return 'packagist';
    }

    public function numericTest($val)
    {
        return ctype_digit((string) $val);
    }

    public function packageExistsTest($package)
    {
        if (!preg_match('/^[A-Za-z0-9_.-]+\/[A-Za-z0-9_.-]+$/', $package)) {
            return false;
        }

        $repo = $this->doctrine->getRepository('PackagistWebBundle:Package');

        return $repo->packageExists($package);
    }

    public function providerExistsTest($package)
    {
        $repo = $this->doctrine->getRepository('PackagistWebBundle:Package');

        return $repo->packageIsProvided($package);
    }

    public function prettifySourceReference($sourceReference)
    {
        if (preg_match('/^[a-f0-9]{40}$/', $sourceReference)) {
            return substr($sourceReference, 0, 7);
        }

        return $sourceReference;
    }

    public function generateGravatarHash($email)
    {
        return md5(strtolower($email));
    }
}
