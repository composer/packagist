<?php declare(strict_types=1);

namespace Packagist\WebBundle\SecurityAdvisory;

use Composer\Downloader\TransportException;
use Composer\Downloader\ZipDownloader;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Packagist\WebBundle\Entity\Package;
use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

class FriendsOfPhpSecurityAdvisoriesSource implements SecurityAdvisorySourceInterface
{
    public const SOURCE_NAME = self::SECURITY_PACKAGE;
    public const SECURITY_PACKAGE = 'sensiolabs/security-advisories';

    /** @var RegistryInterface */
    private $doctrine;
    /** @var LoggerInterface */
    private $logger;

    public function __construct(RegistryInterface $doctrine)
    {
        $this->doctrine = $doctrine;
    }

    public function getAdvisories(ConsoleIO $io): ?array
    {
        /** @var Package $package */
        $package = $this->doctrine->getRepository(Package::class)->findOneBy(['name' => self::SECURITY_PACKAGE]);
        if (!$package || !($version = $package->getVersion('9999999-dev'))) {
            return [];
        }

        $config = Factory::createConfig($io);

        $loader = new ArrayLoader(null, true);
        /** @var CompletePackage $composerPackage */
        $composerPackage = $loader->load($version->toArray([]), CompletePackage::class);

        $localDir = null;
        $advisories = null;
        try {
            $rfs = Factory::createRemoteFilesystem($io, $config, []);
            $downloader = new ZipDownloader($io, $config, null, null, null, $rfs);
            $downloader->setOutputProgress(false);
            $localDir = sys_get_temp_dir() . '/' . uniqid('friends-of-php-advisories', true);
            $downloader->download($composerPackage, $localDir);

            $finder = new Finder();
            $finder->name('*.yaml');
            $advisories = [];
            /** @var \SplFileInfo $file */
            foreach ($finder->in($localDir) as $file) {
                $content = Yaml::parse(file_get_contents($file->getRealPath()));
                $advisories[] = RemoteSecurityAdvisory::createFromFriendsOfPhp($file->getRelativePathname(), $content);
            }
        } catch (TransportException $e) {
            $this->logger->error('Failed to download "sensiolabs/security-advisories" zip file', [
                'exception' => $e,
            ]);
        } finally {
            if ($localDir) {
                $filesystem = new Filesystem();
                $filesystem->remove($localDir);
            }
        }

        return $advisories;
    }
}
