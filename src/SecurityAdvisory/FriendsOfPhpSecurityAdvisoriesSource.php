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

namespace App\SecurityAdvisory;

use Composer\Downloader\TransportException;
use Composer\Factory;
use Composer\IO\ConsoleIO;
use Composer\Package\CompletePackage;
use Composer\Package\Loader\ArrayLoader;
use Composer\Util\Loop;
use Composer\Util\ProcessExecutor;
use App\Entity\Package;
use App\Entity\Version;
use Psr\Log\LoggerInterface;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Yaml\Yaml;

/**
 * @phpstan-import-type FriendsOfPhpSecurityAdvisory from RemoteSecurityAdvisory
 */
class FriendsOfPhpSecurityAdvisoriesSource implements SecurityAdvisorySourceInterface
{
    public const SOURCE_NAME = 'FriendsOfPHP/security-advisories';
    public const SECURITY_PACKAGE = 'sensiolabs/security-advisories';

    private ManagerRegistry $doctrine;
    private LoggerInterface $logger;

    public function __construct(ManagerRegistry $doctrine, LoggerInterface $logger)
    {
        $this->doctrine = $doctrine;
        $this->logger = $logger;
    }

    public function getAdvisories(ConsoleIO $io): ?RemoteSecurityAdvisoryCollection
    {
        $package = $this->doctrine->getRepository(Package::class)->findOneBy(['name' => self::SECURITY_PACKAGE]);
        if (!$package || !($version = $this->doctrine->getRepository(Version::class)->findOneBy(['package' => $package->getId(), 'isDefaultBranch' => true]))) {
            return null;
        }

        $loader = new ArrayLoader(null, true);
        /** @var CompletePackage $composerPackage */
        $composerPackage = $loader->load($version->toArray([]), CompletePackage::class);

        $localCwdDir = null;
        try {
            $localCwdDir = sys_get_temp_dir() . '/' . uniqid(self::SOURCE_NAME, true);
            $localDir = $localCwdDir . '/' . self::SOURCE_NAME;
            $config = Factory::createConfig($io, $localCwdDir);
            $process = new ProcessExecutor();
            $factory = new Factory();
            $httpDownloader = Factory::createHttpDownloader($io, $config);
            $loop = new Loop($httpDownloader, $process);
            $downloadManager = $factory->createDownloadManager($io, $config, $httpDownloader, $process);
            $downloader = $downloadManager->getDownloader('zip');
            $promise = $downloader->download($composerPackage, $localDir);
            $loop->wait([$promise]);
            $promise = $downloader->install($composerPackage, $localDir);
            $loop->wait([$promise]);

            $finder = new Finder();
            $finder->name('*.yaml');
            $advisories = [];
            foreach ($finder->in($localDir) as $file) {
                if (!$file->getRealPath() || !($yaml = file_get_contents($file->getRealPath()))) {
                    continue;
                }
                /** @phpstan-var FriendsOfPhpSecurityAdvisory $content */
                $content = Yaml::parse($yaml);
                $advisories[] = RemoteSecurityAdvisory::createFromFriendsOfPhp($file->getRelativePathname(), $content);
            }

            return new RemoteSecurityAdvisoryCollection($advisories);
        } catch (TransportException $e) {
            $this->logger->error(sprintf('Failed to download "%s" zip file', self::SECURITY_PACKAGE), [
                'exception' => $e,
            ]);

            return null;
        } finally {
            if ($localCwdDir) {
                $filesystem = new Filesystem();
                $filesystem->remove($localCwdDir);
            }
        }
    }
}
