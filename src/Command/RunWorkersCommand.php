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

use App\Service\QueueWorker;
use App\Util\Killswitch;
use Monolog\Logger;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Lock\Exception\LockReleasingException;

class RunWorkersCommand extends Command
{
    use LockableTrait;

    private Logger $logger;
    private QueueWorker $worker;

    public function __construct(Logger $logger, QueueWorker $worker)
    {
        $this->logger = $logger;
        $this->worker = $worker;

        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:run-workers')
            ->setDescription('Run worker services')
            ->addOption('messages', null, InputOption::VALUE_OPTIONAL, 'Amount of messages to process before exiting', 5000)
            ->addOption('worker-id', 'w', InputOption::VALUE_OPTIONAL, 'Unique worker ID', '1')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        \Monolog\ErrorHandler::register($this->logger);

        ini_set('memory_limit', '1G');

        if (!$this->lock('packagist_run_'.$input->getOption('worker-id'))) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another of the same worker is still active');
            }

            return 0;
        }

        if (!Killswitch::isEnabled(Killswitch::WORKERS_ENABLED)) {
            sleep(10);
            $this->release();

            return 0;
        }

        try {
            $this->logger->notice('Worker started successfully');
            $this->logger->reset();

            $this->worker->processMessages((int) $input->getOption('messages'));

            $this->logger->notice('Worker exiting successfully');
        } finally {
            try {
                $this->release();
            } catch (LockReleasingException $e) {
                // during deployments the system v semaphore somehow gets removed before the previous
                // deploy's processes are stopped so this fails to release the lock but is not an actual problem
                if (!str_contains((string) $e->getPrevious()?->getMessage(), 'does not (any longer) exist')) {
                    throw $e;
                }
                try {
                    // force destructor as that will trigger another lock release attempt
                    $this->lock = null;
                } catch (LockReleasingException) {
                }
            }
        }

        return 0;
    }
}
