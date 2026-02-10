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

use App\FilterList\FilterLists;
use App\Service\Locker;
use App\Service\Scheduler;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class UpdateFilterListCommand extends Command
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
            ->setName('packagist:filter-list')
            ->setDefinition([
                new InputArgument('list', InputArgument::REQUIRED, 'The name of the filter list', null, FilterLists::cases()),
            ])
            ->setDescription('Updates all entries for a single filter list')
        ;
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $list = FilterLists::tryFrom($input->getArgument('list'));
        if (!$list) {
            $output->writeln('list must be one of ' . implode(', ', array_map(fn (FilterLists $list) => $list->value, FilterLists::cases())));

            return self::INVALID;
        }

        $lockAcquired = $this->locker->lockFitlerList($list->value);
        if (!$lockAcquired) {
            return 0;
        }

        $this->scheduler->scheduleFilterList($list, 0);
        sleep(2); // sleep to prevent running the same command on multiple machines at around the same time via cron

        $this->locker->unlockFilterList($list->value);

        return 0;
    }
}
