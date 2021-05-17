<?php declare(strict_types=1);

namespace App\Command;

use App\Util\Killswitch;
use Monolog\Logger;
use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use App\Service\QueueWorker;

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

    protected function configure()
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

        if (!$this->lock('packagist_run_' . $input->getOption('worker-id'))) {
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
            $this->release();
        }

        return 0;
    }
}
