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

namespace App\Tests\Organization;

use App\Entity\Organization;
use App\Entity\OrganizationRepository;
use App\Entity\User;
use App\Organization\Domain\Exception\InvalidDisplayNameException;
use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Exception\SlugTakenException;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\OrganizationManager;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OrganizationUpdateTest extends KernelTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->connection = static::getContainer()->get(Connection::class);
        $this->connection->beginTransaction();

        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->connection->rollBack();

        parent::tearDown();
    }

    public function testRenameUpdatesProjectionEventStreamAndTransparencyLog(): void
    {
        $owner = $this->persistOwner('renamer', twoFactor: true);
        $manager = static::getContainer()->get(OrganizationManager::class);
        $organization = $manager->create($owner, 'acme', 'ACME Corp', null);

        $manager->update($this->readModel('acme'), $owner, 'acme', 'ACME Inc', '203.0.113.5');

        self::assertSame('ACME Inc', $this->readModel('acme')->displayName);

        $type = $this->connection->fetchOne(
            'SELECT type FROM organization_event WHERE aggregateId = :id AND sequence = 2',
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame('organization-renamed', $type);

        $auditCount = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE type = 'organization_renamed'",
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testSlugChangeReservesOldSlugAndUpdatesProjection(): void
    {
        $owner = $this->persistOwner('reslugger', twoFactor: true);
        $manager = static::getContainer()->get(OrganizationManager::class);
        $organization = $manager->create($owner, 'acme', 'ACME Corp', null);

        $manager->update($this->readModel('acme'), $owner, 'acme-inc', 'ACME Corp', null);

        // Read model now lives at the new slug.
        self::assertNull($this->readModel('acme'));
        self::assertNotNull($this->readModel('acme-inc'));

        // The freed slug is reserved against the same org.
        $reservation = $this->connection->fetchAssociative(
            'SELECT slug, kind FROM slug_reservation WHERE orgId = :id AND releasedAt IS NULL',
            ['id' => $organization->id->toBinary()],
        );
        self::assertNotFalse($reservation);
        self::assertSame('acme', $reservation['slug']);
        self::assertSame('renamed_from', $reservation['kind']);

        $auditCount = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE type = 'organization_slug_changed'",
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testRenameAndSlugChangeTogetherRecordTwoEvents(): void
    {
        $owner = $this->persistOwner('both', twoFactor: true);
        $manager = static::getContainer()->get(OrganizationManager::class);
        $organization = $manager->create($owner, 'acme', 'ACME Corp', null);

        $manager->update($this->readModel('acme'), $owner, 'acme-inc', 'ACME Inc', null);

        $types = $this->connection->fetchFirstColumn(
            'SELECT type FROM organization_event WHERE aggregateId = :id ORDER BY sequence',
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame(['organization-created', 'organization-renamed', 'organization-slug-changed'], $types);
    }

    public function testUnchangedSubmissionIsNoop(): void
    {
        $owner = $this->persistOwner('noop', twoFactor: true);
        $manager = static::getContainer()->get(OrganizationManager::class);
        $organization = $manager->create($owner, 'acme', 'ACME Corp', null);

        $manager->update($this->readModel('acme'), $owner, 'acme', 'ACME Corp', null);

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM organization_event WHERE aggregateId = :id',
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame(1, (int) $count);
    }

    public function testUpdateRequiresTwoFactor(): void
    {
        $owner = $this->persistOwner('owner2fa', twoFactor: true);
        $manager = static::getContainer()->get(OrganizationManager::class);
        $manager->create($owner, 'acme', 'ACME Corp', null);

        // Drop 2FA after creation.
        $owner->setTotpSecret(null);
        static::getContainer()->get(ManagerRegistry::class)->getManager()->flush();

        $this->expectException(TwoFactorRequiredException::class);
        $manager->update($this->readModel('acme'), $owner, 'acme', 'ACME Inc', null);
    }

    public function testUpdateRejectsReservedSlug(): void
    {
        $owner = $this->persistOwner('reserved', twoFactor: true);
        $manager = static::getContainer()->get(OrganizationManager::class);
        $manager->create($owner, 'acme', 'ACME Corp', null);

        $this->expectException(InvalidSlugException::class);
        $manager->update($this->readModel('acme'), $owner, 'composer', 'ACME Corp', null);
    }

    public function testUpdateRejectsReservedDisplayName(): void
    {
        $owner = $this->persistOwner('reservedname', twoFactor: true);
        $manager = static::getContainer()->get(OrganizationManager::class);
        $manager->create($owner, 'acme', 'ACME Corp', null);

        $this->expectException(InvalidDisplayNameException::class);
        $manager->update($this->readModel('acme'), $owner, 'acme', 'PHP', null);
    }

    public function testUpdateRejectsSlugTakenByAnotherOrg(): void
    {
        $owner = $this->persistOwner('taker', twoFactor: true);
        $manager = static::getContainer()->get(OrganizationManager::class);
        $manager->create($owner, 'acme', 'ACME Corp', null);
        $manager->create($owner, 'globex', 'Globex', null);

        $this->expectException(SlugTakenException::class);
        $manager->update($this->readModel('acme'), $owner, 'globex', 'ACME Corp', null);
    }

    private function readModel(string $slug): ?Organization
    {
        return static::getContainer()->get(OrganizationRepository::class)->findOneBySlug($slug);
    }

    private function persistOwner(string $username, bool $twoFactor): User
    {
        $user = new User();
        $user->setEnabled(true);
        $user->setUsername($username);
        $user->setUsernameCanonical($username);
        $user->setEmail($username.'@example.org');
        $user->setEmailCanonical($username.'@example.org');
        $user->setPassword('testtest');
        if ($twoFactor) {
            $user->setTotpSecret('totp-secret');
        }

        $em = static::getContainer()->get(ManagerRegistry::class)->getManager();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
