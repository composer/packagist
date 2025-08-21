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

use App\Model\ProviderManager;
use App\Service\CdnClient;
use Composer\Pcre\Preg;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Finder\Finder;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class UploadMetadataToCdnCommand extends Command
{
    public function __construct(
        private string $webDir,
        private CdnClient $cdnClient,
        private readonly ProviderManager $providerManager,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:upload-metadata-to-cdn')
            ->setDefinition([])
            ->setDescription('Uploads existing metadata to the CDN')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $verbose = (bool) $input->getOption('verbose');

        $finder = Finder::create()
            ->in($this->webDir.'p2')
            ->name('*.json');

        $processed = 0;
        $batch = [];
        foreach ($finder as $file) {
            $processed++;
            $batch[] = $file->getPathname();
            if (\count($batch) >= 40) {
                $this->uploadBatch($batch);
                $batch = [];
            }
            if (($processed % 10000) === 0) {
                echo 'Processed '.$processed.PHP_EOL;
            }
        }

        if (\count($batch) > 0) {
            $this->uploadBatch($batch);
        }

        echo PHP_EOL.'Done'.PHP_EOL;

        return 0;
    }

    /**
     * @param array<string> $batch
     */
    private function uploadBatch(array $batch): void
    {
        $responses = [];

        foreach ($batch as $path) {
            if (!Preg::isMatch('{/([^/]+/[^/]+?(~dev)?)\.json$}', $path, $match)) {
                throw new \LogicException('Could not match package name from '.$path);
            }
            $pkgWithDevFlag = $match[1];
            $pkgName = str_replace('~dev', '', $pkgWithDevFlag);
            if (!$this->providerManager->packageExists($pkgName)) {
                echo 'Skip uploading '.$pkgName.' metadata as the package does not seem to exist anymore'.PHP_EOL;
                continue;
            }
            $relativePath = 'p2/'.$pkgWithDevFlag.'.json';
            $contents = file_get_contents($path);
            if (false === $contents) {
                throw new \RuntimeException('Failed to read '.$path);
            }
            $responses[] = $this->cdnClient->sendUploadMetadataRequest($relativePath, $contents);
        }

        $successful = 0;
        foreach ($this->cdnClient->stream($responses) as $response => $chunk) {
            try {
                if ($chunk->isFirst()) {
                    if ($response->getStatusCode() !== 201) {
                        throw new \RuntimeException('Failed uploading '.$response->getInfo('user_data')['path']);
                    }
                }
                if ($chunk->isLast()) {
                    $successful++;
                }
            } catch (\Throwable $e) {
                $response->cancel();

                echo 'Failed to upload '.$response->getInfo('user_data')['path'].PHP_EOL;
            }
        }

        if ($successful === count($responses)) {
            echo '.';
        }
    }
}
