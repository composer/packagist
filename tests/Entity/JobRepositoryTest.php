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

namespace App\Tests\Entity;

use App\Entity\Job;
use App\Entity\JobRepository;
use App\Entity\Package;
use App\Tests\IntegrationTestCase;

class JobRepositoryTest extends IntegrationTestCase
{
    private JobRepository $jobRepository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->jobRepository = self::getEM()->getRepository(Job::class);
    }

    public function testFindLatestExecutedJobPicksTheNewestExecutedJobForThePackageAndType(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $other = self::createPackage('test/other', 'https://example.org/other');
        $this->store($package, $other);

        $older = $this->createJob('older000', $package, 'package:updates', '2026-08-01 10:00:00', Job::STATUS_COMPLETED);
        $newest = $this->createJob('newest00', $package, 'package:updates', '2026-08-03 10:00:00', Job::STATUS_FAILED);
        $queued = $this->createJob('queued00', $package, 'package:updates', '2026-08-04 10:00:00', null);
        $foreignType = $this->createJob('foreign0', $package, 'package:delete', '2026-08-05 10:00:00', Job::STATUS_COMPLETED);
        $otherPackage = $this->createJob('otherpkg', $other, 'package:updates', '2026-08-06 10:00:00', Job::STATUS_COMPLETED);
        $this->store($older, $newest, $queued, $foreignType, $otherPackage);

        $found = $this->jobRepository->findLatestExecutedJob($package->getId(), 'package:updates');

        // The query is ORDER BY createdAt DESC LIMIT 1, so the newest executed job must win over
        // the older one, and the queued/foreign rows must not be considered at all.
        self::assertNotNull($found);
        self::assertSame('newest00', $found->getId());
    }

    public function testFindLatestExecutedJobReturnsNullWhenNothingHasRun(): void
    {
        $package = self::createPackage('test/pkg', 'https://example.org/pkg');
        $this->store($package);
        $this->store($this->createJob('queued00', $package, 'package:updates', '2026-08-04 10:00:00', null));

        self::assertNull($this->jobRepository->findLatestExecutedJob($package->getId(), 'package:updates'));
    }

    private function createJob(string $id, Package $package, string $type, string $createdAt, ?string $status): Job
    {
        $job = new Job($id, $type, ['id' => $package->getId(), 'source' => 'test']);
        $job->setPackageId($package->getId());
        $job->setCreatedAt(new \DateTimeImmutable($createdAt));
        if ($status !== null) {
            $job->start();
            $job->complete(['status' => $status, 'message' => 'done']);
        }

        return $job;
    }
}
