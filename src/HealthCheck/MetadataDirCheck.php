<?php declare(strict_types = 1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\HealthCheck;

use Laminas\Diagnostics\Check\CheckInterface;
use Laminas\Diagnostics\Result\Failure;
use Laminas\Diagnostics\Result\ResultInterface;
use Laminas\Diagnostics\Result\Success;
use Symfony\Component\Process\Process;

/**
 * @phpstan-type AwsMetadata array{}|array{ec2_node: string, ip: string, region: string, primary: bool, has_instance_store: bool, is_worker: bool, is_web: bool}
 */
class MetadataDirCheck implements CheckInterface
{
    /**
     * @phpstan-param AwsMetadata $awsMetadata
     */
    public function __construct(private array $awsMetadata)
    {
    }

    public function getLabel(): string
    {
        return 'Check if Packagist metadata dir is present.';
    }

    /**
     * @phpstan-param AwsMetadata $awsMeta
     */
    public static function isMetadataStoreMounted(array $awsMeta): bool
    {
        if (empty($awsMeta)) {
            return true;
        }

        if ($awsMeta['primary'] && $awsMeta['has_instance_store']) {
            $proc = Process::fromShellCommandline('lsblk -io NAME,TYPE,SIZE,MOUNTPOINT,FSTYPE,MODEL | grep Instance | grep sdeph');
            if (0 !== $proc->run()) {
                return false;
            }
        }

        return true;
    }

    public function check(): ResultInterface
    {
        if (empty($this->awsMetadata)) {
            return new Success('No AWS metadata given');
        }

        if ($this->awsMetadata['primary']) {
            if ($this->awsMetadata['has_instance_store']) {
                if (!self::isMetadataStoreMounted($this->awsMetadata)) {
                    return new Failure('Instance store needs configuring');
                }
            }

            $packagesJson = __DIR__ . '/../../web/packages.json';

            if (!file_exists($packagesJson)) {
                return new Failure($packagesJson.' not found on primary server');
            }

            return new Success('Primary server metadata has been dumped');
        }

        $packagesJson = __DIR__ . '/../../web/packages.json';
        if (!file_exists($packagesJson)) {
            return new Failure($packagesJson.' not found');
        }
        if (!is_link($packagesJson)) {
            return new Failure($packagesJson.' is not a symlink');
        }
        if (!$fileContent = file_get_contents($packagesJson)) {
            return new Failure($packagesJson.' is not readable');
        }
        if (substr($fileContent, 0, 1) !== '{') {
            return new Failure($packagesJson.' does not look like it has json in it');
        }
        $metaDir = __DIR__ . '/../../web/p';
        $metaV2Dir = __DIR__ . '/../../web/p2';
        if (!is_dir($metaDir)) {
            return new Failure($metaDir.' not found');
        }
        if (!is_link($metaDir)) {
            return new Failure($metaDir.' is not a symlink');
        }
        if (!is_dir($metaV2Dir)) {
            return new Failure($metaV2Dir.' not found');
        }
        if (!is_link($metaV2Dir)) {
            return new Failure($metaV2Dir.' is not a symlink');
        }

        return new Success('Metadata mirror is symlinked');
    }
}
