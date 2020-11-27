<?php declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Command\LockableTrait;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Command\Command;
use App\Service\QueueWorker;
use Psr\Log\LoggerInterface;

class RunWorkersCommand extends Command
{
    use LockableTrait;

    private $logger;
    private $worker;

    public function __construct(LoggerInterface $logger, QueueWorker $worker)
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

    protected function execute(InputInterface $input, OutputInterface $output)
    {
        \Monolog\ErrorHandler::register($this->logger);

        ini_set('memory_limit', '1G');

        // another dumper is still active
        if (!$this->lock('packagist_run_' . $input->getOption('worker-id'))) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another of the same worker is still active');
            }
            return;
        }

        try {
            $this->logger->notice('Worker started successfully');
            $this->logger->reset();

            $this->worker->processMessages((int) $input->getOption('messages'));

            $this->logger->notice('Worker exiting successfully');
        } finally {
            $this->release();
        }
    }
}
