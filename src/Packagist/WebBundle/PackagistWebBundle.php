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

namespace Packagist\WebBundle;

use Packagist\WebBundle\DependencyInjection\Security\Factory\HttpBasicPreAuthenticatedFactory;
use Symfony\Component\HttpKernel\Bundle\Bundle;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Packagist\WebBundle\DependencyInjection\Compiler\RepositoryPass;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class PackagistWebBundle extends Bundle
{
    public function build(ContainerBuilder $container)
    {
        parent::build($container);

        $extension = $container->getExtension('security');
        $extension->addSecurityListenerFactory(new HttpBasicPreAuthenticatedFactory());

        $container->addCompilerPass(new RepositoryPass());
    }
}
