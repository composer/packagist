<?php

namespace App\DataFixtures;

use App\Entity\Job;
use App\Entity\Package;
use App\Entity\User;
use App\Model\ProviderManager;
use App\Service\UpdaterWorker;
use DateTime;
use Doctrine\Bundle\FixturesBundle\Fixture;
use Doctrine\Common\DataFixtures\DependentFixtureInterface;
use Doctrine\Persistence\ObjectManager;
use Monolog\Logger;
use Seld\Signal\SignalHandler;
use Symfony\Component\Console\Helper\ProgressBar;
use Symfony\Component\Console\Output\ConsoleOutput;

/**
 * Creates packages and updates them from GitHub.
 */
class PackageFixtures extends Fixture implements DependentFixtureInterface
{
    private ProviderManager $providerManager;

    private UpdaterWorker $updateWorker;

    private SignalHandler $signalHandler;

    public function __construct(ProviderManager $providerManager, UpdaterWorker $updaterWorker, Logger $logger)
    {
        $this->providerManager = $providerManager;
        $this->updateWorker = $updaterWorker;
        $this->signalHandler = SignalHandler::create(null, $logger);
    }

    public function getDependencies()
    {
        return [
            UserFixtures::class,
        ];
    }

    public function load(ObjectManager $manager)
    {
        $output = new ConsoleOutput();

        $packages = $this->getPackages();

        $progressBar = new ProgressBar($output, count($packages));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% (%remaining% left) %message%');

        $progressBar->setMessage('');
        $progressBar->start();

        /** @var User $maintainer */
        $maintainer = $this->getReference(UserFixtures::PACKAGE_MAINTAINER);

        foreach ($packages as [$repoUrl, $createdAt]) {
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

            $manager->persist($package);

            $this->providerManager->insertPackage($package);
            $manager->flush();

            $this->updatePackage($package->getId());

            $progressBar->advance();
        }

        $progressBar->finish();

        $output->writeln('');
    }

    private function updatePackage(int $id): void
    {
        $job = new Job('FAKE_ID', 'FAKE_TYPE', [
            'id'                => $id,
            'force_dump'        => false,
            'delete_before'     => false,
            'update_equal_refs' => false,
        ]);

        $this->updateWorker->process($job, $this->signalHandler);
    }

    private function getPackages(): array
    {
        return [
            ['https://github.com/php-fig/log', '2012-12-21T08:45:50+00:00'],
            ['https://github.com/symfony/polyfill-mbstring', '2015-10-25T13:17:47+00:00'],
            ['https://github.com/symfony/console', '2011-10-16T03:41:36+00:00'],
            ['https://github.com/symfony/event-dispatcher', '2011-10-16T03:42:08+00:00'],
            ['https://github.com/doctrine/instantiator', '2014-08-12T01:08:01+00:00'],
            ['https://github.com/symfony/finder', '2011-10-16T03:42:15+00:00'],
            ['https://github.com/guzzle/guzzle', '2011-11-09T04:33:13+00:00'],
            ['https://github.com/php-fig/http-message', '2014-06-10T23:09:12+00:00'],
            ['https://github.com/symfony/process', '2011-10-16T03:42:53+00:00'],
            ['https://github.com/guzzle/psr7', '2015-03-05T23:21:09+00:00'],
            ['https://github.com/doctrine/inflector', '2013-01-10T21:54:25+00:00'],
            ['https://github.com/sebastianbergmann/phpunit', '2012-09-18T06:46:25+00:00'],
            ['https://github.com/phpDocumentor/ReflectionDocBlock', '2012-05-08T17:56:00+00:00'],
            ['https://github.com/Seldaek/monolog', '2011-09-27T00:35:19+00:00'],
            ['https://github.com/sebastianbergmann/php-code-coverage', '2012-09-18T06:59:55+00:00'],
            ['https://github.com/doctrine/lexer', '2013-01-12T19:00:31+00:00'],
            ['https://github.com/sebastianbergmann/php-timer', '2012-09-18T06:58:26+00:00'],
            ['https://github.com/sebastianbergmann/php-file-iterator', '2012-09-18T06:59:23+00:00'],
            ['https://github.com/sebastianbergmann/diff', '2013-02-12T10:19:12+00:00'],
            ['https://github.com/guzzle/promises', '2015-02-25T20:24:35+00:00'],
            ['https://github.com/sebastianbergmann/php-text-template', '2012-08-01T15:59:49+00:00'],
            ['https://github.com/sebastianbergmann/exporter', '2013-02-16T10:01:17+00:00'],
            ['https://github.com/sebastianbergmann/environment', '2014-02-10T15:56:05+00:00'],
            ['https://github.com/sebastianbergmann/php-token-stream', '2012-09-18T06:57:55+00:00'],
            ['https://github.com/phpspec/prophecy', '2013-03-25T11:44:35+00:00'],
            ['https://github.com/paragonie/random_compat', '2015-07-07T20:20:09+00:00'],
            ['https://github.com/sebastianbergmann/version', '2013-01-05T14:28:55+00:00'],
            ['https://github.com/sebastianbergmann/recursion-context', '2015-01-23T07:01:59+00:00'],
            ['https://github.com/webmozarts/assert', '2015-03-11T12:18:50+00:00'],
            ['https://github.com/symfony/debug', '2013-04-07T19:24:19+00:00'],
            ['https://github.com/symfony/http-foundation', '2011-10-16T03:42:31+00:00'],
            ['https://github.com/sebastianbergmann/global-state', '2014-08-23T08:01:04+00:00'],
            ['https://github.com/symfony/translation', '2011-10-16T03:43:49+00:00'],
            ['https://github.com/symfony/yaml', '2011-10-16T03:44:01+00:00'],
            ['https://github.com/phpDocumentor/TypeResolver', '2015-06-12T17:40:32+00:00'],
            ['https://github.com/swiftmailer/swiftmailer', '2011-09-29T17:10:04+00:00'],
            ['https://github.com/phpDocumentor/ReflectionCommon', '2015-06-05T08:49:50+00:00'],
            ['https://github.com/php-fig/container', '2016-09-08T23:35:56+00:00'],
            ['https://github.com/symfony/polyfill-ctype', '2018-05-01T05:39:10+00:00'],
            ['https://github.com/symfony/http-kernel', '2011-10-16T03:42:38+00:00'],
            ['https://github.com/nikic/PHP-Parser', '2012-01-05T12:46:24+00:00'],
            ['https://github.com/sebastianbergmann/comparator', '2014-01-22T07:47:33+00:00'],
            ['https://github.com/symfony/css-selector', '2011-10-16T03:41:43+00:00'],
            ['https://github.com/symfony/var-dumper', '2014-09-26T12:59:44+00:00'],
            ['https://github.com/myclabs/DeepCopy', '2013-07-22T15:50:26+00:00'],
            ['https://github.com/doctrine/cache', '2013-01-10T22:44:33+00:00'],
            ['https://github.com/doctrine/annotations', '2013-01-12T19:24:37+00:00'],
            ['https://github.com/sebastianbergmann/resource-operations', '2015-07-19T06:47:16+00:00'],
            ['https://github.com/sebastianbergmann/code-unit-reverse-lookup', '2016-02-08T20:26:02+00:00'],
            ['https://github.com/sebastianbergmann/object-enumerator', '2016-01-28T06:41:48+00:00'],
            ['https://github.com/symfony/filesystem', '2012-01-10T14:22:45+00:00'],
            ['https://github.com/briannesbitt/Carbon', '2012-09-11T02:06:50+00:00'],
            ['https://github.com/symfony/routing', '2011-10-16T03:43:03+00:00'],
            ['https://github.com/doctrine/dbal', '2011-10-10T17:32:36+00:00'],
            ['https://github.com/sebastianbergmann/phpunit-mock-objects', '2012-09-18T06:55:43+00:00'],
            ['https://github.com/ramsey/uuid', '2015-04-25T19:44:46+00:00'],
            ['https://github.com/symfony/polyfill-php72', '2017-06-09T14:29:13+00:00'],
            ['https://github.com/thephpleague/flysystem', '2014-01-15T07:46:47+00:00'],
            ['https://github.com/php-fig/simple-cache', '2016-11-04T11:18:34+00:00'],
            ['https://github.com/vlucas/phpdotenv', '2013-01-23T07:02:02+00:00'],
            ['https://github.com/doctrine/collections', '2013-01-12T16:39:32+00:00'],
            ['https://github.com/ralouphie/getallheaders', '2014-06-02T21:22:26+00:00'],
            ['https://github.com/doctrine/common', '2011-10-10T17:32:26+00:00'],
            ['https://github.com/twigphp/Twig', '2011-09-29T16:52:42+00:00'],
            ['https://github.com/fzaninotto/Faker', '2011-11-09T14:18:39+00:00'],
            ['https://github.com/theseer/tokenizer', '2017-04-05T18:34:08+00:00'],
            ['https://github.com/sebastianbergmann/object-reflector', '2017-03-12T15:13:02+00:00'],
            ['https://github.com/phar-io/version', '2016-11-30T11:36:58+00:00'],
            ['https://github.com/phar-io/manifest', '2016-11-24T16:53:22+00:00'],
            ['https://github.com/bobthecow/psysh', '2013-06-21T15:04:58+00:00'],
            ['https://github.com/laravel/framework', '2013-01-10T21:31:35+00:00'],
            ['https://github.com/dnoegel/php-xdg-base-dir', '2014-06-30T18:37:36+00:00'],
            ['https://github.com/aws/aws-sdk-php', '2012-11-02T18:05:04+00:00'],
            ['https://github.com/php-fig/cache', '2015-12-11T11:25:13+00:00'],
            ['https://github.com/symfony/polyfill-php70', '2015-10-25T13:15:41+00:00'],
            ['https://github.com/mockery/mockery', '2012-01-21T22:10:42+00:00'],
            ['https://github.com/symfony/dom-crawler', '2011-10-16T03:42:00+00:00'],
            ['https://github.com/symfony/polyfill-intl-idn', '2018-10-01T13:07:00+00:00'],
            ['https://github.com/symfony/config', '2011-10-16T03:41:26+00:00'],
            ['https://github.com/hamcrest/hamcrest-php', '2013-12-31T06:34:20+00:00'],
            ['https://github.com/JakubOnderka/PHP-Console-Color', '2014-04-07T19:56:56+00:00'],
            ['https://github.com/JakubOnderka/PHP-Console-Highlighter', '2013-11-23T13:24:12+00:00'],
            ['https://github.com/egulias/EmailValidator', '2013-05-19T17:04:12+00:00'],
            ['https://github.com/symfony/polyfill-php56', '2015-10-25T13:16:06+00:00'],
            ['https://github.com/symfony/polyfill-php73', '2018-04-25T16:25:32+00:00'],
            ['https://github.com/symfony/service-contracts', '2019-05-27T07:16:14+00:00'],
            ['https://github.com/tijsverkoyen/CssToInlineStyles', '2012-09-30T14:34:16+00:00'],
            ['https://github.com/symfony/polyfill-util', '2015-10-25T13:17:23+00:00'],
            ['https://github.com/schmittjoh/php-option', '2012-11-05T16:24:14+00:00",'],
            ['https://github.com/phpseclib/phpseclib', '2012-06-10T05:33:10+00:00'],
            ['https://github.com/symfony/dependency-injection', '2011-10-16T03:41:53+00:00'],
            ['https://github.com/jmespath/jmespath.php', '2013-11-27T00:36:44+00:00'],
            ['https://github.com/doctrine/orm', '2011-10-10T17:32:08+00:00'],
            ['https://github.com/composer/ca-bundle', '2016-04-11T15:12:41+00:00'],
            ['https://github.com/squizlabs/PHP_CodeSniffer', '2012-11-06T03:18:51+00:00'],
            ['https://github.com/symfony/event-dispatcher-contracts', '2019-05-27T07:08:51+00:00'],
            ['https://github.com/firebase/php-jwt', '2013-08-30T21:20:41+00:00'],
            ['https://github.com/doctrine/event-manager', '2018-06-07T14:18:48+00:00'],
            ['https://github.com/symfony/options-resolver', '2012-05-16T06:01:18+00:00'],
            ['https://github.com/symfony/polyfill-iconv', '2015-10-25T13:15:09+00:00'],
        ];
    }
}
