<?php

namespace App;

use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel as BaseKernel;

ini_set('date.timezone', 'UTC');

class Kernel extends BaseKernel
{
    use MicroKernelTrait {
        configureContainer as parentConfigureContainer;
    }

    protected function configureContainer(ContainerConfigurator $container, LoaderInterface $loader, ContainerBuilder $builder): void
    {
        $configDir = $this->getConfigDir();

        $this->parentConfigureContainer($container, $loader, $builder);

        $container->import($configDir.'/{parameters}.yaml');
    }
}
