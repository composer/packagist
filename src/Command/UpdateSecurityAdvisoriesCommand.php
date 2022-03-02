<?php declare(strict_types=1);

namespace App\Command;

use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use App\Service\Locker;
use App\Service\Scheduler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateSecurityAdvisoriesCommand extends Command
{
    public function __construct(
        private Scheduler $scheduler,
        private Locker $locker,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->setName('packagist:security-advisories')
            ->setDefinition([
                new InputArgument('source', InputArgument::REQUIRED, 'The name of the source'),
            ])
            ->setDescription('Updates all security advisory for a single source')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $source = $input->getArgument('source');
        $sources = [GitHubSecurityAdvisoriesSource::SOURCE_NAME, FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME];
        if (!in_array($source, $sources, true)) {
            $output->writeln('source must be one of ' . implode(', ', $sources));

            return self::INVALID;
        }

        $lockAcquired = $this->locker->lockSecurityAdvisory($source);
        if (!$lockAcquired) {
            return 0;
        }

        $this->scheduler->scheduleSecurityAdvisory($source, 0);
        sleep(2); // sleep to prevent running the same command on multiple machines at around the same time via cron

        $this->locker->unlockSecurityAdvisory($source);

        return 0;
    }
}
