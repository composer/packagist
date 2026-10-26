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

namespace App\Tests\Command;

use App\Command\UpdateFilterListCommand;
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\Service\Locker;
use App\Service\Scheduler;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

class UpdateFilterListCommandTest extends TestCase
{
    public function testLocksPerSource(): void
    {
        $locker = $this->createMock(Locker::class);
        $locker
            ->expects($this->once())
            ->method('lockFilterList')
            ->with('malware-aikido')
            ->willReturn(true);
        $locker
            ->expects($this->once())
            ->method('unlockFilterList')
            ->with('malware-aikido');

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler
            ->expects($this->once())
            ->method('scheduleFilterList')
            ->with(FilterLists::MALWARE, FilterSources::AIKIDO, 0);

        $tester = new CommandTester(new UpdateFilterListCommand($scheduler, $locker));

        $this->assertSame(Command::SUCCESS, $tester->execute(['list' => 'malware', 'source' => 'aikido']));
    }

    public function testDoesNotScheduleWhileTheSameSourceIsLocked(): void
    {
        $locker = $this->createStub(Locker::class);
        $locker
            ->method('lockFilterList')
            ->willReturn(false);

        $scheduler = $this->createMock(Scheduler::class);
        $scheduler
            ->expects($this->never())
            ->method('scheduleFilterList');

        $tester = new CommandTester(new UpdateFilterListCommand($scheduler, $locker));

        $this->assertSame(Command::SUCCESS, $tester->execute(['list' => 'malware', 'source' => 'aikido']));
    }
}
