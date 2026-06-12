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
use Doctrine\Persistence\ManagerRegistry;
use Predis\Client;
use Psr\Log\NullLogger;
use Seld\Signal\SignalHandler;

class SecurityAdvisoryWorkerIntegrationTest extends IntegrationTestCase
{
    /**
     * Exercises the real unique constraint on (packageName, cve): a withdrawn advisory holds a CVE
     * that the source reassigns to another advisory in the same run. The worker must delete the
     * withdrawn advisory (and flush) before resolve() updates the survivor, otherwise Doctrine
     * commits the UPDATE before the DELETE and the package_name_cve_idx constraint is violated.
     */
    public function testWithdrawnAdvisoryRemovalFreesCveForReassignment(): void
    {
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-old-0000-0000', 'acme/package', 'CVE-2024-10001'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $survivor = new SecurityAdvisory($this->remoteAdvisory('GHSA-new-1111-1111', 'acme/package', 'CVE-2024-20002'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($withdrawn, $survivor);

        // GHSA-old is withdrawn at the source; the survivor (GHSA-new) now carries the freed CVE.
        $collection = new RemoteSecurityAdvisoryCollection(
            [$this->remoteAdvisory('GHSA-new-1111-1111', 'acme/package', 'CVE-2024-10001')],
            ['acme/package' => ['GHSA-old-0000-0000' => true]],
        );

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/package');

        $this->assertCount(1, $advisories);
        $this->assertSame('GHSA-new-1111-1111', $advisories[0]->getRemoteId());
        $this->assertSame('CVE-2024-10001', $advisories[0]->getCve());
    }

    public function testPureOrphanWithdrawnAdvisoryIsRemoved(): void
    {
        $withdrawn = new SecurityAdvisory($this->remoteAdvisory('GHSA-orphan-0000', 'acme/orphan', 'CVE-2024-30003'), GitHubSecurityAdvisoriesSource::SOURCE_NAME);
        $this->store($withdrawn);

        // The source reports the advisory only as withdrawn, with no live advisory for the package.
        $collection = new RemoteSecurityAdvisoryCollection([], ['acme/orphan' => ['GHSA-orphan-0000' => true]]);

        $this->runWorkerWithSource($collection);

        $this->getEM()->clear();
        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->findByPackageName('acme/orphan');

        $this->assertSame([], $advisories);
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
