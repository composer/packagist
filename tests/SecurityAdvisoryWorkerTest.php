<?php declare(strict_types=1);

namespace App\Tests;

use App\Entity\Package;
use Doctrine\ORM\EntityManager;
use Doctrine\ORM\EntityRepository;
use App\Entity\Job;
use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\SecurityAdvisorySourceInterface;
use App\Service\Locker;
use App\Service\SecurityAdvisoryWorker;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Seld\Signal\SignalHandler;
use Doctrine\Persistence\ManagerRegistry;

class SecurityAdvisoryWorkerTest extends TestCase
{
    /** @var SecurityAdvisoryWorker */
    private $worker;
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
        $this->worker = new SecurityAdvisoryWorker($locker, new NullLogger(), $doctrine, ['test' => $this->source]);

        $this->em = $this->getMockBuilder(EntityManager::class)->disableOriginalConstructor()->getMock();

        $doctrine
            ->method('getManager')
            ->willReturn($this->em);

        $locker
            ->method('lockSecurityAdvisory')
            ->willReturn(true);

        $this->securityAdvisoryRepository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();
        $this->packageRepository = $this->getMockBuilder(EntityRepository::class)->disableOriginalConstructor()->getMock();

        $doctrine
            ->method('getRepository')
            ->withConsecutive(
                [$this->equalTo(Package::class)],
                [$this->equalTo(SecurityAdvisory::class)]
            )
            ->willReturnOnConsecutiveCalls(
                $this->packageRepository,
                $this->securityAdvisoryRepository
            );
    }

    public function testProcess(): void
    {
        $advisory1Existing = $this->getMockBuilder(RemoteSecurityAdvisory::class)->disableOriginalConstructor()->getMock();
        $advisory2New = $this->getMockBuilder(RemoteSecurityAdvisory::class)->disableOriginalConstructor()->getMock();
        $advisories = [
            $advisory1Existing,
            $advisory2New,
        ];

        $advisory1Existing
            ->method('getId')
            ->willReturn('remote-id-1');

        $advisory2New
            ->method('getId')
            ->willReturn('remote-id-2');

        $advisory2New
            ->method('getPackageName')
            ->willReturn('package/new');

        $existingAdvisory1 = $this->getMockBuilder(SecurityAdvisory::class)->disableOriginalConstructor()->getMock();
        $existingAdvisory1
            ->method('getRemoteId')
            ->willReturn('remote-id-1');

        $existingAdvisory1
            ->expects($this->once())
            ->method('updateAdvisory')
            ->with($this->equalTo($advisory1Existing));

        $existingAdvisory2ToBeDeleted = $this->getMockBuilder(SecurityAdvisory::class)->disableOriginalConstructor()->getMock();
        $existingAdvisory2ToBeDeleted
            ->method('getRemoteId')
            ->willReturn('to-be-deleted');

        $existingAdvisory2ToBeDeleted
            ->method('getPackageName')
            ->willReturn('vendor/delete');

        $this->source
            ->expects($this->once())
            ->method('getAdvisories')
            ->willReturn($advisories);

        $this->em
            ->expects($this->once())
            ->method('persist');

        $this->em
            ->expects($this->once())
            ->method('remove')
            ->with($this->equalTo($existingAdvisory2ToBeDeleted));

        $this->securityAdvisoryRepository
            ->method('findBy')
            ->with($this->equalTo(['source' => 'test']))
            ->willReturn([$existingAdvisory1, $existingAdvisory2ToBeDeleted]);

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
            ->willReturn([]);

        $this->em
            ->expects($this->never())
            ->method('persist');

        $this->securityAdvisoryRepository
            ->method('findBy')
            ->with($this->equalTo(['source' => 'test']))
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
}
