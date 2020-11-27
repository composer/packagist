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

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SymlinkMetadataMirrorCommand extends Command
{
    public function __construct(string $webDir, string $metadataDir, array $awsMetadata)
    {
        $this->webDir = realpath($webDir);
        $this->buildDir = $metadataDir;
        $this->awsMeta = $awsMetadata;
        parent::__construct();
    }

    /**
     * {@inheritdoc}
     */
    protected function configure()
    {
        $this
            ->setName('packagist:symlink-metadata')
            ->setDefinition(array())
            ->setDescription('Symlinks metadata dir to the mirrored metadata, if needed')
        ;
    }

    /**
     * {@inheritdoc}
     */
    protected function execute(InputInterface $input, OutputInterface $output)
    {
        if (empty($this->awsMeta) || !$this->awsMeta['is_web'] || $this->awsMeta['primary']) {
            return 0;
        }

        $verbose = (bool) $input->getOption('verbose');

        $sources = [
            $this->buildDir.'/p',
            $this->buildDir.'/p2',
            $this->buildDir.'/packages.json',
            $this->buildDir.'/packages.json.gz',
        ];

        foreach ($sources as $source) {
            if (!file_exists($source)) {
                if ($verbose) {
                    $output->writeln('Can not link to '.$source.' as it is missing');
                }
                continue;
            }

            $target = $this->webDir.'/'.basename($source);
            if (file_exists($target) && is_link($target) && readlink($target) === $source) {
                continue;
            }
            if ($verbose) {
                $output->writeln('Linking '.$target.' => '.$source);
            }
            if (!symlink($source, $target)) {
                $output->writeln('Warning: Could not symlink '.$source.' into the web dir ('.$target.')');
                throw new \RuntimeException('Could not symlink '.$source.' into the web dir ('.$target.')');
            }
        }

        return 0;
    }
}
