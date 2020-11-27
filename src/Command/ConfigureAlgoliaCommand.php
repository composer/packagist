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

namespace App\Command;

use Algolia\AlgoliaSearch\SearchClient;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

class ConfigureAlgoliaCommand extends Command
{
    private SearchClient $algolia;
    private string $algoliaIndexName;

    public function __construct(SearchClient $algolia, string $algoliaIndexName)
    {
        $this->algolia = $algolia;
        $this->algoliaIndexName = $algoliaIndexName;
        parent::__construct();
    }

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
        $settings = Yaml::parse(
            file_get_contents(__DIR__.'/../config/algolia_settings.yml')
        );

        $index = $this->algolia->initIndex($this->algoliaIndexName);

        $index->setSettings($settings);
    }
}
