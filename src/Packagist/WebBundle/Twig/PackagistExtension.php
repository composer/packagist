<?php

namespace Packagist\WebBundle\Twig;

use Packagist\WebBundle\Model\ProviderManager;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;

class PackagistExtension extends \Twig\Extension\AbstractExtension
{
    /**
     * @var ProviderManager
     */
    private $providerManager;
    /** @var CsrfTokenManagerInterface */
    private $csrfTokenManager;

    public function __construct(ProviderManager $providerManager, CsrfTokenManagerInterface$csrfTokenManager)
    {
        $this->providerManager = $providerManager;
        $this->csrfTokenManager = $csrfTokenManager;
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
            new \Twig_SimpleFilter('gravatar_hash', [$this, 'generateGravatarHash']),
            new \Twig_SimpleFilter('vendor', [$this, 'getVendor']),
        );
    }

    public function getFunctions()
    {
        return array(
            new \Twig_SimpleFunction('csrf_token', [$this, 'getCsrfToken']),
        );
    }

    public function getName()
    {
        return 'packagist';
    }

    public function getVendor(string $packageName): string
    {
        return preg_replace('{/.*$}', '', $packageName);
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

        return $this->providerManager->packageExists($package);
    }

    public function providerExistsTest($package)
    {
        return $this->providerManager->packageIsProvided($package);
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

    public function getCsrfToken($name)
    {
        return $this->csrfTokenManager->getToken($name)->getValue();
    }
}
