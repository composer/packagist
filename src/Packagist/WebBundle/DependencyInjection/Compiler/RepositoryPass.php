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

namespace Packagist\WebBundle\DependencyInjection\Compiler;

use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;

/**
 * Adds VCS repository providers to the main repository_provider service
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class RepositoryPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container)
    {
        if (!$container->hasDefinition('packagist.repository_provider')) {
            return;
        }

        $provider = $container->getDefinition('packagist.repository_provider');
        $providers = array();

        foreach ($container->findTaggedServiceIds('packagist.repository_provider') as $id => $tags) {
            $providers[$id] = isset($tags[0]['priority']) ? (int) $tags[0]['priority'] : 0;
        }

        arsort($providers);

        foreach ($providers as $id => $priority) {
            $provider->addMethodCall('addProvider', array(new Reference($id)));
        }
    }
}
