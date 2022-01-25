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
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

#[AsCommand(name: 'algolia:configure', description: 'Configure Algolia index')]
class ConfigureAlgoliaCommand extends Command
{
    public function __construct(
        private SearchClient $algolia,
        private string $algoliaIndexName,
        private string $configDir,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $yaml = file_get_contents($this->configDir.'algolia_settings.yml');

        if (!$yaml) {
            throw new \RuntimeException('Algolia config file not readable.');
        }

        $settings = Yaml::parse($yaml);

        $index = $this->algolia->initIndex($this->algoliaIndexName);

        $index->setSettings($settings);

        return 0;
    }
}
