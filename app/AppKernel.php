<?php

use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\HttpKernel\Kernel;

class AppKernel extends Kernel
{
    public function registerBundles()
    {
        $bundles = array(
            new Symfony\Bundle\FrameworkBundle\FrameworkBundle(),
            new Symfony\Bundle\SecurityBundle\SecurityBundle(),
            new Symfony\Bundle\TwigBundle\TwigBundle(),
            new Symfony\Bundle\MonologBundle\MonologBundle(),
            new Symfony\Bundle\SwiftmailerBundle\SwiftmailerBundle(),
            new Doctrine\Bundle\DoctrineBundle\DoctrineBundle(),
            new Doctrine\Bundle\DoctrineCacheBundle\DoctrineCacheBundle(),
            new Sensio\Bundle\FrameworkExtraBundle\SensioFrameworkExtraBundle(),
            new FOS\UserBundle\FOSUserBundle(),
            new Http\HttplugBundle\HttplugBundle(),
            new HWI\Bundle\OAuthBundle\HWIOAuthBundle(),
            new Snc\RedisBundle\SncRedisBundle(),
            new BabDev\PagerfantaBundle\BabDevPagerfantaBundle(),
            new Nelmio\SecurityBundle\NelmioSecurityBundle(),
            new Knp\Bundle\MenuBundle\KnpMenuBundle(),
            new Nelmio\CorsBundle\NelmioCorsBundle(),
            new Scheb\TwoFactorBundle\SchebTwoFactorBundle(),
            new Endroid\QrCodeBundle\EndroidQrCodeBundle(),
            new Beelab\Recaptcha2Bundle\BeelabRecaptcha2Bundle(),
            new Packagist\WebBundle\PackagistWebBundle(),
        );

        if (in_array($this->getEnvironment(), array('dev', 'test'))) {
            $bundles[] = new Symfony\Bundle\WebProfilerBundle\WebProfilerBundle();
        }

        return $bundles;
    }

    public function registerContainerConfiguration(LoaderInterface $loader)
    {
        $loader->load(__DIR__.'/config/config_'.$this->getEnvironment().'.yml');
    }

    /**
     * {@inheritdoc}
     *
     * @note Overridden path is to retain the pre-existing path structure and can be removed if moving to Symfony defaults
     */
    public function getCacheDir()
    {
        return $this->getProjectDir().'/app/cache/'.$this->environment;
    }

    /**
     * {@inheritdoc}
     *
     * @note Overridden path is to retain the pre-existing path structure and can be removed if moving to Symfony defaults
     */
    public function getLogDir()
    {
        return $this->getProjectDir().'/app/logs';
    }
}
