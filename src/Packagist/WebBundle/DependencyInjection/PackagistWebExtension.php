<?php

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Packagist\WebBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\HttpKernel\DependencyInjection\Extension;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackagistWebExtension extends Extension
{
    public function load(array $configs, ContainerBuilder $container)
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__.'/../Resources/config'));
        $loader->load('services.yml');

        $container->setParameter('packagist_web.rss_max_items', $config['rss_max_items']);

	    if (!empty($config['preauthenticated_provider']['enabled'])) {
		    $this->loadPreAuthenticatedProvider($container);
	    }
    }

	private function loadPreAuthenticatedProvider(ContainerBuilder $container)
	{
		$baseUserProvider    = $container->getDefinition('packagist.user_provider');
		$preAuthUserProvider = $container->getDefinition('packagist.http_basic_preauthenticated_user_provider');

		$container->setDefinition('packagist.base_user_provider', $baseUserProvider);
		$preAuthUserProvider->replaceArgument(0, new Reference('packagist.base_user_provider'));

		$container->setDefinition('packagist.user_provider', $preAuthUserProvider);
	}
}
