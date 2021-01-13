<?php declare(strict_types = 1);

namespace App\HealthCheck;

use ZendDiagnostics\Check\AbstractCheck;
use ZendDiagnostics\Result\Failure;
use ZendDiagnostics\Result\Success;
use ZendDiagnostics\Result\Warning;
use Symfony\Component\Process\Process;

class MetadataDirCheck extends AbstractCheck
{
    /** @var array */
    private $awsMeta;

    public function __construct(array $awsMetadata)
    {
        $this->awsMeta = $awsMetadata;
    }

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

    public function check()
    {
        if (empty($this->awsMeta)) {
            return new Success('No AWS metadata given');
        }

        if ($this->awsMeta['primary']) {
            if ($this->awsMeta['has_instance_store']) {
                if (!self::isMetadataStoreMounted($this->awsMeta)) {
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
        if (substr(file_get_contents($packagesJson), 0, 1) !== '{') {
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
