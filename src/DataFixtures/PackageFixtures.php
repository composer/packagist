<?php

namespace App\DataFixtures;

use App\Entity\Job;
use App\Entity\Package;
use App\Entity\User;
use App\Model\ProviderManager;
use App\Service\UpdaterWorker;
use DateInterval;
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

        $urls = $this->getPackageUrls();

        $progressBar = new ProgressBar($output, count($urls));
        $progressBar->setFormat('%current%/%max% [%bar%] %percent:3s%% (%remaining% left) %message%');

        $progressBar->setMessage('');
        $progressBar->start();

        /** @var User $maintainer */
        $maintainer = $this->getReference(UserFixtures::PACKAGE_MAINTAINER);

        foreach ($urls as $url) {
            /**
             * The EntityManager gets cleared by the UpdaterWorker, so the User becomes detached.
             * We need to re-load into the current EntityManager on every loop iteration.
             *
             * @var User $maintainer
             */
            $maintainer = $manager->find(User::class, $maintainer->getId());

            $progressBar->setMessage($url);
            $progressBar->display();

            $package = new Package;
            $package->setCreatedAt((new DateTime('now'))->sub(new DateInterval('P1Y')));
            $package->addMaintainer($maintainer);
            $package->setRepository($url);

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

    private function getPackageUrls(): array
    {
        return [
            'https://github.com/php-fig/log',
            'https://github.com/symfony/polyfill-mbstring',
            'https://github.com/symfony/console',
            'https://github.com/symfony/event-dispatcher',
            'https://github.com/doctrine/instantiator',
            'https://github.com/symfony/finder',
            'https://github.com/guzzle/guzzle',
            'https://github.com/php-fig/http-message',
            'https://github.com/symfony/process',
            'https://github.com/guzzle/psr7',
            'https://github.com/doctrine/inflector',
            'https://github.com/sebastianbergmann/phpunit',
            'https://github.com/phpDocumentor/ReflectionDocBlock',
            'https://github.com/Seldaek/monolog',
            'https://github.com/sebastianbergmann/php-code-coverage',
            'https://github.com/doctrine/lexer',
            'https://github.com/sebastianbergmann/php-timer',
            'https://github.com/sebastianbergmann/php-file-iterator',
            'https://github.com/sebastianbergmann/diff',
            'https://github.com/guzzle/promises',
            'https://github.com/sebastianbergmann/php-text-template',
            'https://github.com/sebastianbergmann/exporter',
            'https://github.com/sebastianbergmann/environment',
            'https://github.com/sebastianbergmann/php-token-stream',
            'https://github.com/phpspec/prophecy',
            'https://github.com/paragonie/random_compat',
            'https://github.com/sebastianbergmann/version',
            'https://github.com/sebastianbergmann/recursion-context',
            'https://github.com/webmozarts/assert',
            'https://github.com/symfony/debug',
            'https://github.com/symfony/http-foundation',
            'https://github.com/sebastianbergmann/global-state',
            'https://github.com/symfony/translation',
            'https://github.com/symfony/yaml',
            'https://github.com/phpDocumentor/TypeResolver',
            'https://github.com/swiftmailer/swiftmailer',
            'https://github.com/phpDocumentor/ReflectionCommon',
            'https://github.com/php-fig/container',
            'https://github.com/symfony/polyfill-ctype',
            'https://github.com/symfony/http-kernel',
            'https://github.com/nikic/PHP-Parser',
            'https://github.com/sebastianbergmann/comparator',
            'https://github.com/symfony/css-selector',
            'https://github.com/symfony/var-dumper',
            'https://github.com/myclabs/DeepCopy',
            'https://github.com/doctrine/cache',
            'https://github.com/doctrine/annotations',
            'https://github.com/sebastianbergmann/resource-operations',
            'https://github.com/sebastianbergmann/code-unit-reverse-lookup',
            'https://github.com/sebastianbergmann/object-enumerator',
            'https://github.com/symfony/filesystem',
            'https://github.com/briannesbitt/Carbon',
            'https://github.com/symfony/routing',
            'https://github.com/doctrine/dbal',
            'https://github.com/sebastianbergmann/phpunit-mock-objects',
            'https://github.com/ramsey/uuid',
            'https://github.com/symfony/polyfill-php72',
            'https://github.com/thephpleague/flysystem',
            'https://github.com/php-fig/simple-cache',
            'https://github.com/vlucas/phpdotenv',
            'https://github.com/doctrine/collections',
            'https://github.com/ralouphie/getallheaders',
            'https://github.com/doctrine/common',
            'https://github.com/twigphp/Twig',
            'https://github.com/fzaninotto/Faker',
            'https://github.com/theseer/tokenizer',
            'https://github.com/sebastianbergmann/object-reflector',
            'https://github.com/phar-io/version',
            'https://github.com/phar-io/manifest',
            'https://github.com/bobthecow/psysh',
            'https://github.com/laravel/framework',
            'https://github.com/dnoegel/php-xdg-base-dir',
            'https://github.com/aws/aws-sdk-php',
            'https://github.com/php-fig/cache',
            'https://github.com/symfony/polyfill-php70',
            'https://github.com/mockery/mockery',
            'https://github.com/symfony/dom-crawler',
            'https://github.com/symfony/polyfill-intl-idn',
            'https://github.com/symfony/config',
            'https://github.com/hamcrest/hamcrest-php',
            'https://github.com/JakubOnderka/PHP-Console-Color',
            'https://github.com/JakubOnderka/PHP-Console-Highlighter',
            'https://github.com/egulias/EmailValidator',
            'https://github.com/symfony/polyfill-php56',
            'https://github.com/symfony/polyfill-php73',
            'https://github.com/symfony/service-contracts',
            'https://github.com/tijsverkoyen/CssToInlineStyles',
            'https://github.com/symfony/polyfill-util',
            'https://github.com/schmittjoh/php-option',
            'https://github.com/phpseclib/phpseclib',
            'https://github.com/symfony/dependency-injection',
            'https://github.com/jmespath/jmespath.php',
            'https://github.com/doctrine/orm',
            'https://github.com/composer/ca-bundle',
            'https://github.com/squizlabs/PHP_CodeSniffer',
            'https://github.com/symfony/event-dispatcher-contracts',
            'https://github.com/firebase/php-jwt',
            'https://github.com/doctrine/event-manager',
            'https://github.com/symfony/options-resolver',
            'https://github.com/symfony/polyfill-iconv',
        ];
    }
}
