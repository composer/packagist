<?php

declare(strict_types=1);

namespace App\DataFixtures;

use App\Entity\Job;
use App\Entity\Package;
use App\Entity\User;
use App\Entity\Vendor;
use App\Model\ProviderManager;
use App\Service\UpdaterWorker;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Bundle\FixturesBundle\FixtureGroupInterface;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Monolog\Logger;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Creates packages and updates them from GitHub.
 */
class PackageFixtures extends Fixture implements DependentFixtureInterface, FixtureGroupInterface
{
    private SignalHandler $signalHandler;

    public function __construct(
        private ProviderManager $providerManager,
        private UpdaterWorker $updaterWorker,
        Logger $logger
    ) {
        $this->signalHandler = SignalHandler::create(null, $logger);
    }

    public static function getGroups(): array
    {
        return ['base'];
    }

    public function getDependencies(): array
    {
        return [
            UserFixtures::class,
        ];
    }

    public function load(ObjectManager $manager): void
    {
        $output = new ConsoleOutput();

        $packages = $this->getPackages();

        echo 'Creating '.count($packages).' packages. "composer/pcre" has full version information, the others only one branch and one tag each.' . PHP_EOL;

        $progressBar = new ProgressBar($output, count($packages));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% (%remaining% left) %message%');

        $progressBar->setMessage('');
        $progressBar->start();

        $maintainer = $this->getReference(UserFixtures::PACKAGE_MAINTAINER, User::class);

        foreach ($packages as [$repoUrl, $createdAt]) {
            $mtime = microtime(true);
            /**
             * The EntityManager gets cleared by the UpdaterWorker, so the User becomes detached.
             * We need to re-load into the current EntityManager on every loop iteration.
             *
             * @var User $maintainer
             */
            $maintainer = $manager->find(User::class, $maintainer->getId());

            $progressBar->setMessage($repoUrl);
            $progressBar->display();

            $package = new Package;
            $package->setCreatedAt(new DateTime($createdAt));
            $package->addMaintainer($maintainer);
            $package->setRepository($repoUrl);

            if (!$package->getName()) {
                var_dump($repoUrl.' needs to be updated or removed in '.__FILE__.' as it is not loadable anymore');
                continue;
            }

            $manager->getRepository(Vendor::class)->createIfNotExists($package->getVendor());
            $manager->persist($package);

            $this->providerManager->insertPackage($package);
            $manager->flush();

            $this->updaterWorker->setLoadMinimalVersions($package->getName() !== 'composer/pcre');
            $this->updatePackage($package->getId());

            $progressBar->advance();
        }

        $progressBar->finish();
        $manager->clear();

        $output->writeln('');
    }

    private function updatePackage(int $id): void
    {
        $job = new Job('FAKE_ID', 'FAKE_TYPE', [
            'id'                => $id,
            'update_equal_refs' => false,
            'delete_before'     => false,
            'force_dump'        => false,
            'source'            => 'fixtures',
        ]);

        $this->updaterWorker->process($job, $this->signalHandler);
    }

    /**
     * @return array<array{0: string, 1: string}>
     */
    private function getPackages(): array
    {
        return [
            ['https://github.com/composer/pcre', '2016-04-11T15:12:41+00:00'],
            ['https://github.com/Seldaek/monolog', '2011-09-27T00:35:19+00:00'],
            ['https://github.com/briannesbitt/Carbon', '2012-09-11T02:06:50+00:00'],
            ['https://github.com/doctrine/event-manager', '2018-06-07T14:18:48+00:00'],
            ['https://github.com/doctrine/inflector', '2013-01-10T21:54:25+00:00'],
            ['https://github.com/doctrine/instantiator', '2014-08-12T01:08:01+00:00'],
            ['https://github.com/firebase/php-jwt', '2013-08-30T21:20:41+00:00'],
            ['https://github.com/jmespath/jmespath.php', '2013-11-27T00:36:44+00:00'],
            ['https://github.com/php-fig/http-message', '2014-06-10T23:09:12+00:00'],
            ['https://github.com/php-fig/log', '2012-12-21T08:45:50+00:00'],
            ['https://github.com/phpseclib/phpseclib', '2012-06-10T05:33:10+00:00'],
            ['https://github.com/phpspec/prophecy', '2013-03-25T11:44:35+00:00'],
            ['https://github.com/ramsey/uuid', '2015-04-25T19:44:46+00:00'],
            ['https://github.com/schmittjoh/php-option', '2012-11-05T16:24:14+00:00,'],
            ['https://github.com/sebastianbergmann/comparator', '2014-01-22T07:47:33+00:00'],
            ['https://github.com/sebastianbergmann/diff', '2013-02-12T10:19:12+00:00'],
            ['https://github.com/sebastianbergmann/environment', '2014-02-10T15:56:05+00:00'],
            ['https://github.com/sebastianbergmann/exporter', '2013-02-16T10:01:17+00:00'],
            ['https://github.com/sebastianbergmann/global-state', '2014-08-23T08:01:04+00:00'],
            ['https://github.com/sebastianbergmann/php-code-coverage', '2012-09-18T06:59:55+00:00'],
            ['https://github.com/sebastianbergmann/php-file-iterator', '2012-09-18T06:59:23+00:00'],
            ['https://github.com/sebastianbergmann/php-text-template', '2012-08-01T15:59:49+00:00'],
            ['https://github.com/sebastianbergmann/php-timer', '2012-09-18T06:58:26+00:00'],
            ['https://github.com/sebastianbergmann/php-token-stream', '2012-09-18T06:57:55+00:00'],
            ['https://github.com/sebastianbergmann/phpunit-mock-objects', '2012-09-18T06:55:43+00:00'],
            ['https://github.com/sebastianbergmann/recursion-context', '2015-01-23T07:01:59+00:00'],
            ['https://github.com/sebastianbergmann/version', '2013-01-05T14:28:55+00:00'],
            ['https://github.com/squizlabs/PHP_CodeSniffer', '2012-11-06T03:18:51+00:00'],
            ['https://github.com/thephpleague/flysystem', '2014-01-15T07:46:47+00:00'],
            ['https://github.com/twigphp/Twig', '2011-09-29T16:52:42+00:00'],
            ['https://github.com/webmozarts/assert', '2015-03-11T12:18:50+00:00'],
            ['https://github.com/zenstruck/schedule-bundle', '2022-03-11T12:18:50+00:00'],
            ['https://github.com/zenstruck/signed-url-bundle', '2022-03-11T12:18:50+00:00'],
        ];
    }
}
