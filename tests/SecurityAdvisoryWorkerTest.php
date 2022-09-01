<?php declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use App\Entity\SecurityAdvisoryRepository;
use App\EventListener\SecurityAdvisoryUpdateListener;
use App\SecurityAdvisory\RemoteSecurityAdvisoryCollection;
use App\SecurityAdvisory\SecurityAdvisoryResolver;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use App\Entity\Job;
use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\SecurityAdvisorySourceInterface;
use App\Service\Locker;
use App\Service\SecurityAdvisoryWorker;
use PHPUnit\Framework\TestCase;
use Predis\Client;
use Psr\Log\NullLogger;
use Seld\Signal\SignalHandler;
use Doctrine\Persistence\ManagerRegistry;

class SecurityAdvisoryWorkerTest extends TestCase
{
    private SecurityAdvisoryWorker $worker;
    /** @var SecurityAdvisorySourceInterface&\PHPUnit\Framework\MockObject\MockObject */
    private $source;
    /** @var EntityManager&\PHPUnit\Framework\MockObject\MockObject */
    private $em;
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $securityAdvisoryRepository;
    /** @var \PHPUnit\Framework\MockObject\MockObject */
    private $packageRepository;

    protected function setUp(): void
    {
        $this->source = $this->getMockBuilder(SecurityAdvisorySourceInterface::class)->disableOriginalConstructor()->getMock();
        $locker = $this->getMockBuilder(Locker::class)->disableOriginalConstructor()->getMock();
        $doctrine = $this->getMockBuilder(ManagerRegistry::class)->disableOriginalConstructor()->getMock();
        $redis = $this->getMockBuilder(Client::class)->disableOriginalConstructor()->getMock();
        $this->worker = new SecurityAdvisoryWorker($locker, new NullLogger(), $doctrine, ['test' => $this->source], new SecurityAdvisoryResolver(), new SecurityAdvisoryUpdateListener($doctrine, $redis));

        $this->em = $this->getMockBuilder(EntityManager::class)->disableOriginalConstructor()->getMock();

        $doctrine
            ->method('getManager')
            ->willReturn($this->em);

        $this->em
            ->method('getConnection')
            ->willReturn($this->getMockBuilder(Connection::class)->disableOriginalConstructor()->getMock());

        $locker
            ->method('lockSecurityAdvisory')
            ->willReturn(true);

        $this->securityAdvisoryRepository = $this->getMockBuilder(SecurityAdvisoryRepository::class)->disableOriginalConstructor()->getMock();
        $this->packageRepository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();

        $doctrine
            ->method('getRepository')
            ->willReturnMap([
                [Package::class, null, $this->packageRepository],
                [SecurityAdvisory::class, null, $this->securityAdvisoryRepository],
            ]);
    }

    public function testProcess(): void
    {
        $advisory1Existing = $this->createRemoteAdvisory('package/existing', 'remote-id-1');
        $advisory2New = $this->createRemoteAdvisory('package/new', 'remote-id-2');
        $advisories = [
            $advisory1Existing,
            $advisory2New,
        ];

        $existingAdvisory1 = new SecurityAdvisory($advisory1Existing, 'test');
        $existingAdvisory2ToBeDeleted = new SecurityAdvisory($this->createRemoteAdvisory('vendor/delete', 'to-be-deleted'), 'test');

        $this->source
            ->expects($this->once())
            ->method('getAdvisories')
            ->willReturn(new RemoteSecurityAdvisoryCollection($advisories));

        $this->em
            ->expects($this->once())
            ->method('persist');

        $this->em
            ->expects($this->once())
            ->method('remove')
            ->with($this->equalTo($existingAdvisory2ToBeDeleted));

        $this->securityAdvisoryRepository
            ->method('getPackageAdvisoriesWithSources')
            ->with($this->equalTo(['package/existing', 'package/new']))
            ->willReturn([$existingAdvisory1->getPackagistAdvisoryId() => $existingAdvisory1, $existingAdvisory2ToBeDeleted->getPackagistAdvisoryId() => $existingAdvisory2ToBeDeleted]);

        $this->packageRepository
            ->method('findOneBy')
            ->with(['id' => 42])
            ->willReturn(new Package());

        $job = new Job('job', 'security:advisory', ['source' => 'test']);
        $job->setPackageId(42);
        $this->worker->process($job, SignalHandler::create());
    }

    public function testProcessNoAdvisories(): void
    {
        $this->source
            ->expects($this->once())
            ->method('getAdvisories')
            ->willReturn(new RemoteSecurityAdvisoryCollection([]));

        $this->em
            ->expects($this->never())
            ->method('persist');

        $this->securityAdvisoryRepository
            ->method('getPackageAdvisoriesWithSources')
            ->with($this->equalTo([]))
            ->willReturn([]);

        $this->packageRepository
            ->method('findOneBy')
            ->with(['id' => 42])
            ->willReturn(new Package());

        $job = new Job('job', 'security:advisory', ['source' => 'test']);
        $job->setPackageId(42);
        $this->worker->process($job, SignalHandler::create());
    }

    public function testProcessAdvisoryFailed(): void
    {
        $this->source
            ->expects($this->once())
            ->method('getAdvisories')
            ->willReturn(null);

        $this->em
            ->expects($this->never())
            ->method('flush');

        $this->securityAdvisoryRepository
            ->expects($this->never())
            ->method('findBy');

        $this->packageRepository
            ->method('findOneBy')
            ->with(['id' => 42])
            ->willReturn(new Package());

        $job = new Job('job', 'security:advisory', ['source' => 'test']);
        $job->setPackageId(42);
        $this->worker->process($job, SignalHandler::create());
    }

    private function createRemoteAdvisory(string $packageName, string $remoteId): RemoteSecurityAdvisory
    {
        return new RemoteSecurityAdvisory($remoteId, 'Advisory' . $packageName, $packageName, '^1.0', 'https://example/' . $packageName, null, new \DateTimeImmutable(), null, [], 'test');
    }
}
