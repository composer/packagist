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

namespace App\Tests\Controller;

use App\Audit\AbandonmentReason;
use App\Audit\AuditLogSearchType;
use App\Audit\AuditRecordType;
use App\Entity\Package;
use App\Entity\PackageFreezeReason;
use App\Event\PackageAbandonedEvent;
use App\Event\PackageUnabandonedEvent;
use App\Tests\Fixtures\Fixtures;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Security\Core\Authentication\Token\Storage\TokenStorageInterface;
use Symfony\Component\Security\Core\Authentication\Token\UsernamePasswordToken;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

class PackageAuditRecordTest extends KernelTestCase
{
    use Fixtures;

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

    public function testPackageChangesGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $package = self::createPackage('composer/composer', 'https://github.com/composer/composer');
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageCreated->value, $logs[0]['type']);

        // Change the repository property through reflection, to avoid the costly network-based initialization
        new \ReflectionProperty($package, 'repository')->setValue($package, 'https://github.com/composer/packagist');
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(2, $logs);
        self::assertSame(AuditRecordType::CanonicalUrlChanged->value, $logs[0]['type']);
        self::assertSame('{"name": "composer/composer", "actor": "unknown", "repository_to": "https://github.com/composer/packagist", "repository_from": "https://github.com/composer/composer"}', $logs[0]['attributes']);

        $em->remove($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs);
        self::assertSame(AuditRecordType::PackageDeleted->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertArrayHasKey('reason', $attributes);
        self::assertNull($attributes['reason']);
        self::assertNull($attributes['internalReason']);
    }

    public function testModeratorSubmissionRecordsBothActorAndMaintainer(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $moderator = self::createUser('moderator', 'moderator@example.org', githubId: '2', roles: ['ROLE_EDIT_PACKAGES']);
        $maintainer = self::createUser('newowner', 'newowner@example.org', githubId: '3');
        $em->persist($moderator);
        $em->persist($maintainer);
        $em->flush();

        // the listener reads the acting user off the token, which loginUser() cannot provide for a
        // bare persist()
        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($moderator, 'main', $moderator->getRoles()));

        $package = self::createPackage('acme/handed-over', 'https://github.com/acme/handed-over');
        $package->setSubmittedOnBehalfOf($maintainer);
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ?', [AuditRecordType::PackageCreated->value]);
        self::assertCount(1, $logs);
        self::assertSame($moderator->getId(), $logs[0]['actorId']);
        self::assertSame($maintainer->getId(), $logs[0]['userId']);

        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('moderator', $attributes['actor']['username']);
        self::assertSame('newowner', $attributes['user']['username']);

        $terms = $container->get(Connection::class)->fetchFirstColumn('SELECT name FROM audit_log_search WHERE auditLogId = ? AND type = ?', [$logs[0]['id'], AuditLogSearchType::User->value]);
        self::assertSame(['newowner'], $terms);
    }

    public function testSelfSubmissionRecordsNoMaintainer(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        // also covers a moderator typing their own username into the assign field: same end state
        $user = self::createUser('selfsubmitter', 'selfsubmitter@example.org', githubId: '2');
        $em->persist($user);
        $em->flush();

        $container->get(TokenStorageInterface::class)->setToken(new UsernamePasswordToken($user, 'main', $user->getRoles()));

        $package = self::createPackage('acme/self-submitted', 'https://github.com/acme/self-submitted', maintainers: [$user]);
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ?', [AuditRecordType::PackageCreated->value]);
        self::assertCount(1, $logs);
        self::assertSame($user->getId(), $logs[0]['actorId']);
        self::assertNull($logs[0]['userId']);
        self::assertArrayNotHasKey('user', json_decode($logs[0]['attributes'], true));
    }

    public function testSubmissionWithoutAnActingUserRecordsTheMaintainer(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        // the API authenticates in the controller, so there is no security token to act as the actor
        $user = self::createUser('apiuser', 'apiuser@example.org', githubId: '2');
        $em->persist($user);
        $em->flush();

        $package = self::createPackage('acme/api-submitted', 'https://github.com/acme/api-submitted', maintainers: [$user]);
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ?', [AuditRecordType::PackageCreated->value]);
        self::assertCount(1, $logs);
        self::assertNull($logs[0]['actorId']);
        self::assertSame($user->getId(), $logs[0]['userId']);
        self::assertSame('apiuser', json_decode($logs[0]['attributes'], true)['user']['username']);
    }

    public function testPackageDeletionReasonsGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $package = self::createPackage('vendor/reasons', 'https://github.com/vendor/reasons');
        $em->persist($package);
        $em->flush();

        // Transient carriers, set by PackageManager::deletePackage() before removal, are read by
        // PackageListener::preRemove() to document the deletion in the audit log.
        $package->setAuditDeletionReason('public takedown notice');
        $package->setAuditDeletionInternalReason('internal: reporter john@example.com, ticket #42');
        $em->remove($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ? ORDER BY id DESC', [AuditRecordType::PackageDeleted->value]);
        self::assertCount(1, $logs);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('public takedown notice', $attributes['reason']);
        self::assertSame('internal: reporter john@example.com, ticket #42', $attributes['internalReason']);
    }

    public function testPackageAbandonmentGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $eventDispatcher = $container->get(EventDispatcherInterface::class);

        $package = self::createPackage('test/package', 'https://github.com/test/package');

        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageCreated->value, $logs[0]['type']);

        // Test abandonment with replacement package
        $package->setAbandoned(true);
        $package->setReplacementPackage('test/replacement');
        $eventDispatcher->dispatch(new PackageAbandonedEvent($package, AbandonmentReason::Unknown));

        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(2, $logs);
        self::assertSame(AuditRecordType::PackageAbandoned->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/package', $attributes['name']);
        self::assertSame('https://github.com/test/package', $attributes['repository']);
        self::assertSame('test/replacement', $attributes['replacement_package']);
        self::assertSame('automation', $attributes['actor']);
        // When abandoned directly via entity setAbandoned, reason defaults to 'unknown'
        self::assertArrayHasKey('reason', $attributes);
        self::assertSame('unknown', $attributes['reason']);

        // Test unabandonment
        $package->setAbandoned(false);
        $package->setReplacementPackage(null);
        $eventDispatcher->dispatch(new PackageUnabandonedEvent($package, AbandonmentReason::Unknown));
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(3, $logs);
        self::assertSame(AuditRecordType::PackageUnabandoned->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/package', $attributes['name']);
        self::assertSame('https://github.com/test/package', $attributes['repository']);
        self::assertSame('automation', $attributes['actor']);
    }

    public function testPackageAbandonmentWithoutReplacementGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();
        $eventDispatcher = $container->get(EventDispatcherInterface::class);

        $package = self::createPackage('test/package2', 'https://github.com/test/package2');

        $em->persist($package);
        $em->flush();

        // Test abandonment without replacement package
        $package->setAbandoned(true);
        $em->persist($package);
        $eventDispatcher->dispatch(new PackageAbandonedEvent($package, AbandonmentReason::Unknown));
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ? ORDER BY id DESC', [AuditRecordType::PackageAbandoned->value]);
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageAbandoned->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/package2', $attributes['name']);
        self::assertNull($attributes['replacement_package']);
        self::assertArrayHasKey('reason', $attributes);
        self::assertSame('unknown', $attributes['reason']);

        // Test unabandonment when there was no replacement package
        $package->setAbandoned(false);
        $em->persist($package);
        $eventDispatcher->dispatch(new PackageUnabandonedEvent($package, AbandonmentReason::Unknown));
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ? ORDER BY id DESC', [AuditRecordType::PackageUnabandoned->value]);
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageUnabandoned->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/package2', $attributes['name']);
    }

    public function testPackageFreezingGetRecorded(): void
    {
        $container = static::getContainer();
        $em = $container->get(ManagerRegistry::class)->getManager();

        $package = self::createPackage('test/freeze-package', 'https://github.com/test/freeze-package');

        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log ORDER BY id DESC');
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageCreated->value, $logs[0]['type']);

        // Test freezing
        $package->freeze(PackageFreezeReason::Spam);
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ? ORDER BY id DESC', [AuditRecordType::PackageFrozen->value]);
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageFrozen->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/freeze-package', $attributes['name']);
        self::assertSame('https://github.com/test/freeze-package', $attributes['repository']);
        self::assertSame('spam', $attributes['reason']);
        self::assertSame('automation', $attributes['actor']);

        // Test unfreezing
        $package->unfreeze();
        $em->persist($package);
        $em->flush();

        $logs = $container->get(Connection::class)->fetchAllAssociative('SELECT * FROM audit_log WHERE type = ? ORDER BY id DESC', [AuditRecordType::PackageUnfrozen->value]);
        self::assertCount(1, $logs);
        self::assertSame(AuditRecordType::PackageUnfrozen->value, $logs[0]['type']);
        $attributes = json_decode($logs[0]['attributes'], true);
        self::assertSame('test/freeze-package', $attributes['name']);
        self::assertSame('https://github.com/test/freeze-package', $attributes['repository']);
        self::assertSame('automation', $attributes['actor']);
    }
}
