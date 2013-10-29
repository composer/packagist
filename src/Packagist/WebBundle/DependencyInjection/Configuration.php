<?php

namespace Packagist\WebBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * This is the class that validates and merges configuration from your app/config files
 *
 * To learn more see {@link http://symfony.com/doc/current/cookbook/bundles/extension.html#cookbook-bundles-extension-config-class}
 */
class Configuration implements ConfigurationInterface
{
    /**
     * {@inheritDoc}
     */
    public function getConfigTreeBuilder()
    {
        $treeBuilder = new TreeBuilder();
        $rootNode = $treeBuilder->root('packagist_web');

        $rootNode
            ->children()
                ->scalarNode('rss_max_items')->defaultValue(40)->end()
                ->arrayNode('preauthenticated_provider')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('default_email_domain')->defaultValue('example.com')->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
