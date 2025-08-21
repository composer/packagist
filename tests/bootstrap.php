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

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

if (file_exists(dirname(__DIR__).'/config/bootstrap.php')) {
    require dirname(__DIR__).'/config/bootstrap.php';
} elseif (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

// hack for PHPUnit 11, see https://github.com/symfony/symfony/issues/53812
set_exception_handler([new Symfony\Component\ErrorHandler\ErrorHandler(), 'handleException']);

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

/**
 * Executes a given command.
 *
 * @param string $command a command to execute
 *
 * @throws Exception when the return code is not 0.
 */
function executeCommand(string $command, bool $errorHandling = true): void
{
    $output = [];

    $returnCode = null;

    exec($command, $output, $returnCode);

    if ($errorHandling && $returnCode !== 0) {
        throw new Exception(
            sprintf(
                'Error executing command "%s", return code was "%s".',
                $command,
                $returnCode
            )
        );
    }
}

if (!getenv('QUICK')) {
    echo 'For quicker test runs without a fresh DB schema, prefix the test command with a QUICK=1 env var.' . PHP_EOL;

    executeCommand('php ./bin/console doctrine:database:drop --env=test --force -q', false);
    executeCommand('php ./bin/console doctrine:database:create --env=test -q');
    executeCommand('php ./bin/console doctrine:schema:create --env=test -q');
    executeCommand('php ./bin/console redis:query flushall --env=test -n -q');
}

Composer\Util\Platform::putEnv('PACKAGIST_TESTS_ARE_RUNNING', '1');
