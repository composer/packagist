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

    public function testForceDumpIsCarriedOverToAnAlreadyPendingJob(): void
    {
        // force_dump is not part of the dedup key, so without carrying it over a plain job queued by
        // e.g. a webhook push would swallow the request entirely — which is how a soft-deleted version
        // or an unfreeze could silently fail to be re-dumped.
        $pending = $this->scheduler->scheduleUpdate($this->package, 'webhook');
        self::assertFalse($pending->getPayload()['force_dump']);

        $returned = $this->scheduler->scheduleUpdate($this->package, 'version_soft_delete', forceDump: true);

        self::assertSame($pending->getId(), $returned->getId(), 'the pending job should be reused, not duplicated');
        self::assertCount(1, $this->queuedUpdateJobs(), 'no second job should be queued for the same package');

        self::getEM()->clear();
        $reloaded = self::getEM()->getRepository(Job::class)->find($pending->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->getPayload()['force_dump'], 'the pending job must be upgraded to force a re-dump');
        self::assertSame('webhook', $reloaded->getPayload()['source'], 'the rest of the payload must be left alone');
    }

    public function testAPendingForcedJobIsNotDowngradedByALaterPlainRequest(): void
    {
        $pending = $this->scheduler->scheduleUpdate($this->package, 'version_soft_delete', forceDump: true);

        $this->scheduler->scheduleUpdate($this->package, 'webhook');

        self::getEM()->clear();
        $reloaded = self::getEM()->getRepository(Job::class)->find($pending->getId());
        self::assertNotNull($reloaded);
        self::assertTrue($reloaded->getPayload()['force_dump']);
    }

    public function testAForcedJobScheduledForLaterKeepsForcingWhenCancelledForAnImmediateOne(): void
    {
        // Not hypothetical: UpdaterWorker reschedules on lock contention, re-queueing the job *with* an
        // executeAfter. A plain webhook push then takes the cancel-and-recreate path, which used to
        // recreate the job with the caller's plain payload and silently drop the force.
        $pending = $this->scheduler->scheduleUpdate($this->package, 'version_soft_delete', executeAfter: new \DateTimeImmutable('+1 hour'), forceDump: true);

        $returned = $this->scheduler->scheduleUpdate($this->package, 'webhook');

        self::assertNotSame($pending->getId(), $returned->getId(), 'the scheduled-for-later job should be replaced by an immediate one');
        self::assertTrue($returned->getPayload()['force_dump'], 'the replacement job must inherit the force the cancelled job was carrying');

        self::getEM()->clear();
        $cancelled = self::getEM()->getRepository(Job::class)->find($pending->getId());
        self::assertNotNull($cancelled);
        self::assertSame(Job::STATUS_COMPLETED, $cancelled->getStatus());
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
