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
    public const SOURCE_NAME = 'FriendsOfPHP/security-advisories';
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

        $loader = new ArrayLoader(null, true);
        /** @var CompletePackage $composerPackage */
        $composerPackage = $loader->load($version->toArray([]), CompletePackage::class);

        $localCwdDir = null;
        $advisories = null;
        try {
            $localCwdDir = sys_get_temp_dir() . '/' . uniqid(self::SOURCE_NAME, true);
            $localDir = $localCwdDir . '/' . self::SOURCE_NAME;
            $config = Factory::createConfig($io, $localCwdDir);
            $rfs = Factory::createRemoteFilesystem($io, $config, []);
            $downloader = new ZipDownloader($io, $config, null, null, null, $rfs);
            $downloader->setOutputProgress(false);
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
            $this->logger->error(sprintf('Failed to download "%s" zip file', self::SECURITY_PACKAGE), [
                'exception' => $e,
            ]);
        } finally {
            if ($localCwdDir) {
                $filesystem = new Filesystem();
                $filesystem->remove($localCwdDir);
            }
        }

        return $advisories;
    }
}
