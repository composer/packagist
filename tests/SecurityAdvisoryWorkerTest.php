<?php declare(strict_types=1);

namespace App\Tests;

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

        $doctrine
            ->method('getRepository')
            ->with($this->equalTo(SecurityAdvisory::class))
            ->willReturn($this->securityAdvisoryRepository);
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

        $job = new Job();
        $job->setPayload(['source' => 'test']);
        $this->worker->process($job, SignalHandler::create());
    }

    public function testProcessNone(): void
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

        $job = new Job();
        $job->setPayload(['source' => 'test']);
        $this->worker->process($job, SignalHandler::create());
    }

    public function testProcessFailed(): void
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

        $job = new Job();
        $job->setPayload(['source' => 'test']);
        $this->worker->process($job, SignalHandler::create());
    }
}
