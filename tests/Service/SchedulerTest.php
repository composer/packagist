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

namespace App\Tests\Service;

use App\Entity\Job;
use App\Entity\Package;
use App\Service\Scheduler;
use App\Tests\IntegrationTestCase;

class SchedulerTest extends IntegrationTestCase
{
    private Scheduler $scheduler;
    private Package $package;

    protected function setUp(): void
    {
        parent::setUp();

        $this->scheduler = static::getContainer()->get(Scheduler::class);
        $this->package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($this->package);
    }

    public function testAForcedRequestIsNotSwallowedByAPendingPlainJob(): void
    {
        // force_dump is part of the dedup key, so a plain job queued by e.g. a webhook push cannot
        // absorb a forced request and silently drop the forcing.
        $pending = $this->scheduler->scheduleUpdate($this->package, 'webhook');
        self::assertFalse($pending->getPayload()['force_dump']);

        $returned = $this->scheduler->scheduleUpdate($this->package, 'button/api', forceDump: true);

        self::assertNotSame($pending->getId(), $returned->getId());
        self::assertTrue($returned->getPayload()['force_dump']);
        self::assertCount(2, $this->queuedUpdateJobs(), 'forced schedules are rare, so a second crawl is an acceptable price');
    }

    public function testAPendingForcedJobIsNotDowngradedByALaterPlainRequest(): void
    {
        $pending = $this->scheduler->scheduleUpdate($this->package, 'button/api', forceDump: true);

        $this->scheduler->scheduleUpdate($this->package, 'webhook');

        self::getEM()->clear();
        $reloaded = self::getEM()->getRepository(Job::class)->find($pending->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->getPayload()['force_dump']);
        self::assertSame(Job::STATUS_QUEUED, $reloaded->getStatus(), 'the forced job must not be cancelled by the plain one');
    }

    public function testTwoPlainRequestsStillDedupe(): void
    {
        $pending = $this->scheduler->scheduleUpdate($this->package, 'webhook');

        $returned = $this->scheduler->scheduleUpdate($this->package, 'webhook');

        self::assertSame($pending->getId(), $returned->getId());
        self::assertCount(1, $this->queuedUpdateJobs(), 'the common push path must not start duplicating crawls');
    }

    public function testAForcedJobScheduledForLaterIsNotCancelledByAnImmediatePlainOne(): void
    {
        // UpdaterWorker reschedules on lock contention, re-queueing the job *with* an executeAfter. A
        // plain push must not take the cancel-and-recreate path against it, or the forcing is lost.
        $pending = $this->scheduler->scheduleUpdate($this->package, 'button/api', executeAfter: new \DateTimeImmutable('+1 hour'), forceDump: true);

        $returned = $this->scheduler->scheduleUpdate($this->package, 'webhook');

        self::assertNotSame($pending->getId(), $returned->getId());

        self::getEM()->clear();
        $reloaded = self::getEM()->getRepository(Job::class)->find($pending->getId());
        self::assertNotNull($reloaded);
        self::assertSame(Job::STATUS_QUEUED, $reloaded->getStatus(), 'the forced job must survive to run at its scheduled time');
        self::assertTrue($reloaded->getPayload()['force_dump']);
    }

    /**
     * @return array<Job<array<string, mixed>>>
     */
    private function queuedUpdateJobs(): array
    {
        return self::getEM()->getRepository(Job::class)->findBy([
            'type' => 'package:updates',
            'packageId' => $this->package->getId(),
        ]);
    }
}
