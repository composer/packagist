<?php declare(strict_types=1);

namespace Packagist\WebBundle\Command;

use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\LockHandler;
use Symfony\Bundle\FrameworkBundle\Command\ContainerAwareCommand;
use Seld\Signal\SignalHandler;

class RunWorkersCommand extends ContainerAwareCommand
{
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
        $lock = new LockHandler('packagist_run_' . $input->getOption('worker-id'));

        // another dumper is still active
        if (!$lock->lock()) {
            if ($input->getOption('verbose')) {
                $output->writeln('Aborting, another of the same worker is still active');
            }
            return;
        }

        try {
            $logger = $this->getContainer()->get('logger');

            $worker = $this->getContainer()->get('packagist.queue_worker');

            $logger->notice('Worker started successfully');
            $this->getContainer()->get('packagist.log_resetter')->reset();

            $worker->processMessages((int) $input->getOption('messages'));

            $logger->notice('Worker exiting successfully');
        } finally {
            $lock->release();
        }
    }
}
