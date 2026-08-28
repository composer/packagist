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

namespace App\Tests;

use App\Entity\Job;
use App\Entity\SecurityAdvisory;
use App\EventListener\SecurityAdvisoryUpdateListener;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisoryCollection;
use App\SecurityAdvisory\SecurityAdvisoryResolver;
use App\SecurityAdvisory\SecurityAdvisorySourceInterface;
use App\Service\Locker;
use App\Service\SecurityAdvisoryWorker;
use Composer\IO\ConsoleIO;
use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Psr\Log\NullLogger;
use Seld\Signal\SignalHandler;

class SecurityAdvisoryWorkerIntegrationTest extends IntegrationTestCase
{
    /**
     * Guards the package_name_cve_idx unique index itself: two ACTIVE (withdrawnAt IS NULL)
     * advisories must never coexist for the same (packageName, cve). This is the case a naive
     * (packageName, cve, withdrawnAt) composite index would fail to catch, because MySQL exempts a
     * row from a unique index entirely once any indexed column is NULL - and withdrawnAt is NULL for
     * every active row. The activeCve generated column (NULL only when withdrawn) is what makes this
     * still throw.
     */
    public function testTwoActiveAdvisoriesCannotShareTheSameCve(): void
    {
        $first = new SecurityAdvisory($this->remoteAdvisory('GHSA-aaaa-0000-0000', 'acme/package', 'CVE-2024-40004'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $second = new SecurityAdvisory($this->remoteAdvisory('GHSA-bbbb-1111-1111', 'acme/package', 'CVE-2024-40004'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);

        $this->expectException(UniqueConstraintViolationException::class);

        $this->store($first, $second);
    }

    /**
     * Exercises the real unique constraint on (packageName, activeCve): a withdrawn advisory keeps
     * its CVE (with a non-null withdrawnAt, so activeCve is NULL), while the source reassigns the
     * same CVE to another (live) advisory in the same run. Since activeCve is NULL for the withdrawn
     * row, it is exempt from the uniqueness check and both rows can coexist.
     */
    public function testWithdrawnAdvisoryFreesCveForReassignment(): void
    {
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-old-0000-0000', 'acme/package', 'CVE-2024-10001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $survivor = new SecurityAdvisory($this->remoteAdvisory('GHSA-new-1111-1111', 'acme/package', 'CVE-2024-20002'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($withdrawn, $survivor);

        $collection = new RemoteSecurityAdvisoryCollection(
            [$this->remoteAdvisory('GHSA-new-1111-1111', 'acme/package', 'CVE-2024-10001')],
            ['acme/package' => ['GHSA-old-0000-0000' => true]],
        );

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/package');

        $this->assertCount(2, $advisories);

        $active = array_values(array_filter($advisories, static fn (SecurityAdvisory $a) => !$a->isWithdrawn()));
        $withdrawnAdvisories = array_values(array_filter($advisories, static fn (SecurityAdvisory $a) => $a->isWithdrawn()));

        $this->assertCount(1, $active);
        $this->assertSame('GHSA-new-1111-1111', $active[0]->getRemoteId());
        $this->assertSame('CVE-2024-10001', $active[0]->getCve());

        $this->assertCount(1, $withdrawnAdvisories);
        $this->assertSame('GHSA-old-0000-0000', $withdrawnAdvisories[0]->getRemoteId());
        $this->assertSame('CVE-2024-10001', $withdrawnAdvisories[0]->getCve());
        $this->assertNotNull($withdrawnAdvisories[0]->getWithdrawnAt());
    }

    public function testPureOrphanWithdrawnAdvisoryIsKeptButMarkedWithdrawn(): void
    {
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-orphan-0000', 'acme/orphan', 'CVE-2024-30003'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($withdrawn);

        $collection = new RemoteSecurityAdvisoryCollection([], ['acme/orphan' => ['GHSA-orphan-0000' => true]]);

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/orphan');

        $this->assertCount(1, $advisories);
        $this->assertTrue($advisories[0]->isWithdrawn());
        $this->assertNotNull($advisories[0]->getWithdrawnAt());

        // Withdrawn advisories must not surface in the active composer audit / API results any more.
        $active = $this->getEM()->getRepository(SecurityAdvisory::class)->getPackageSecurityAdvisories('acme/orphan');
        $this->assertSame([], $active);
    }

    /**
     * Same reassignment as {@see self::testWithdrawnAdvisoryFreesCveForReassignment()}, but the
     * advisory that inherits the CVE was stored first, so Doctrine computes its change set (and runs
     * its UPDATE) before the withdrawal UPDATE. Both land in the same flush, so without the worker's
     * intermediate flush the outcome hangs on entity order and can trip package_name_cve_idx.
     */
    public function testWithdrawnAdvisoryFreesCveForReassignmentWhateverTheEntityOrder(): void
    {
        $survivor = new SecurityAdvisory($this->remoteAdvisory('GHSA-live-2222-2222', 'acme/reordered', 'CVE-2024-40004'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-stale-3333-3333', 'acme/reordered', 'CVE-2024-50005'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($survivor, $withdrawn);

        $collection = new RemoteSecurityAdvisoryCollection(
            [$this->remoteAdvisory('GHSA-live-2222-2222', 'acme/reordered', 'CVE-2024-50005')],
            ['acme/reordered' => ['GHSA-stale-3333-3333' => true]],
        );

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/reordered');

        $this->assertCount(2, $advisories);
        $this->assertSame('CVE-2024-50005', $this->activeAdvisory($advisories)->getCve());
    }

    /**
     * The withdrawn advisory keeps its CVE and the source publishes a brand new advisory carrying
     * that same CVE in the same run. removeWithdrawn() leaves the withdrawn advisory out of the list
     * handed to resolve(), so the replacement is persisted as a new entity, and Doctrine commits
     * inserts before updates - the withdrawal must be flushed first for the insert to fit.
     */
    public function testWithdrawnAdvisoryFreesCveForAReplacementInsertedInTheSameRun(): void
    {
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-gone-4444-4444', 'acme/replaced', 'CVE-2024-60006'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($withdrawn);

        $collection = new RemoteSecurityAdvisoryCollection(
            [$this->remoteAdvisory('GHSA-fresh-5555-5555', 'acme/replaced', 'CVE-2024-60006')],
            ['acme/replaced' => ['GHSA-gone-4444-4444' => true]],
        );

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/replaced');

        $this->assertCount(2, $advisories);
        $this->assertSame('GHSA-fresh-5555-5555', $this->activeAdvisory($advisories)->getRemoteId());
    }

    /**
     * @param SecurityAdvisory[] $advisories
     */
    private function activeAdvisory(array $advisories): SecurityAdvisory
    {
        $active = array_values(array_filter($advisories, static fn (SecurityAdvisory $a) => !$a->isWithdrawn()));
        $this->assertCount(1, $active);

        return $active[0];
    }

    private function runWorkerWithSource(RemoteSecurityAdvisoryCollection $collection): void
    {
        $doctrine = static::getContainer()->get(ManagerRegistry::class);
        \assert($doctrine instanceof ManagerRegistry);

        $source = new class($collection) implements SecurityAdvisorySourceInterface {
            public function __construct(private readonly RemoteSecurityAdvisoryCollection $collection)
            {
            }

            public function getAdvisories(ConsoleIO $io): ?RemoteSecurityAdvisoryCollection
            {
                return $this->collection;
            }
        };

        $worker = new SecurityAdvisoryWorker(
            new Locker($doctrine),
            new NullLogger(),
            $doctrine,
            [GitHubSecurityAdvisoriesSource::SOURCE_NAME => $source],
            new SecurityAdvisoryResolver(),
            new SecurityAdvisoryUpdateListener($doctrine, $this->createStub(Client::class)),
        );

        $job = new Job('advisory-job', 'security:advisory', ['source' => GitHubSecurityAdvisoriesSource::SOURCE_NAME]);
        $result = $worker->process($job, SignalHandler::create());

        $this->assertSame(Job::STATUS_COMPLETED, $result['status']);
    }

    private function remoteAdvisory(string $remoteId, string $packageName, string $cve): RemoteSecurityAdvisory
    {
        return new RemoteSecurityAdvisory(
            $remoteId,
            'Advisory '.$cve,
            $packageName,
            '^1.0',
            'https://example.org/'.$remoteId,
            $cve,
            new \DateTimeImmutable('2024-01-01 00:00:00'),
            null,
            [],
            GitHubSecurityAdvisoriesSource::SOURCE_NAME,
            null,
        );
    }
}
