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

namespace Packagist\WebBundle\Command;

use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * @author Igor Wiedler <igor@wiedler.ch>
 */
class ConfigureAlgoliaCommand extends ContainerAwareCommand
{
    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('algolia:configure')
            ->setDescription('Configure Algolia index')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        $index_name = $this->getContainer()->getParameter('algolia.index_name');
        $settings = Yaml::parse(
            file_get_contents(__DIR__.'/../Resources/config/algolia_settings.yml')
        );

        $algolia = $this->getContainer()->get('packagist.algolia.client');
        $index = $algolia->initIndex($index_name);

        $index->setSettings($settings);
    }
}
