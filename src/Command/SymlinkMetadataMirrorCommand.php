<?php declare(strict_types=1);

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
use Symfony\Component\Console\Output\OutputInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SymlinkMetadataMirrorCommand extends Command
{
    public function __construct(
        private string $webDir,
        private string $metadataDir,
        /** @phpstan-var AwsMetadata */
        private array $awsMetadata,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:symlink-metadata')
            ->setDefinition([])
            ->setDescription('Symlinks metadata dir to the mirrored metadata, if needed')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        if (empty($this->awsMetadata) || !$this->awsMetadata['is_web'] || $this->awsMetadata['primary']) {
            return 0;
        }

        $verbose = (bool) $input->getOption('verbose');

        $sources = [
            $this->metadataDir.'/p',
            $this->metadataDir.'/p2',
            $this->metadataDir.'/packages.json',
            $this->metadataDir.'/packages.json.gz',
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
