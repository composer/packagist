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

namespace App\Tests\Audit;

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\RequireLink;
use App\Entity\User;
use App\Entity\Version;
use App\Event\VersionReferenceChangedEvent;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\EventDispatcher\EventDispatcher;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class AuditRecordTest extends KernelTestCase
{
    protected function setUp(): void
    {
        self::bootKernel();
        static::getContainer()->get(Connection::class)->beginTransaction();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        static::getContainer()->get(Connection::class)->rollBack();

        parent::tearDown();
    }

    public function testPersistedAuditRecordIsEnrichedWithClientIp(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $requestStack = $container->get(RequestStack::class);
        $requestStack->push(new Request(server: [
            'REMOTE_ADDR' => '192.168.1.1',
        ]));

        $package = new Package();
        $package->setRepository('https://github.com/composer/composer');

        $em->persist($package);
        $em->flush();

        $record = AuditRecord::packageCreated($package, null);

        $em->persist($record);
        $em->flush();
        $em->clear();

        $insertedRecord = $em->getRepository(AuditRecord::class)->find($record->id);

        $this->assertNotNull($insertedRecord);
        $this->assertSame('192.168.1.1', $record->ip);
    }
}
