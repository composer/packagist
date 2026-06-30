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
use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Exception\SlugTakenException;
use App\Organization\OrganizationManager;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;

class OrganizationEditTest extends IntegrationTestCase
{
    private Connection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = self::getService(Connection::class);
    }

    public function testChangeNameUpdatesProjectionEventStreamAndTransparencyLog(): void
    {
        $owner = $this->persistOwner('renamer', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $organization = $manager->create($owner, 'acme', 'ACME Corp', null);

        $manager->edit($this->readModel('acme'), $owner, 'acme', 'ACME Inc', '203.0.113.5');

        self::assertSame('ACME Inc', $this->readModel('acme')->displayName);

        $type = $this->connection->fetchOne(
            'SELECT type FROM organization_event WHERE aggregateId = :id AND sequence = 2',
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame('organization-name-changed', $type);

        $auditCount = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE type = 'organization_name_changed'",
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testSlugChangeReservesOldSlugAndUpdatesProjection(): void
    {
        $owner = $this->persistOwner('reslugger', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $organization = $manager->create($owner, 'acme', 'ACME Corp', null);

        $manager->edit($this->readModel('acme'), $owner, 'acme-inc', 'ACME Corp', null);

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

    public function testNameAndSlugChangeTogetherRecordTwoEvents(): void
    {
        $owner = $this->persistOwner('both', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $organization = $manager->create($owner, 'acme', 'ACME Corp', null);

        $manager->edit($this->readModel('acme'), $owner, 'acme-inc', 'ACME Inc', null);

        $types = $this->connection->fetchFirstColumn(
            'SELECT type FROM organization_event WHERE aggregateId = :id ORDER BY sequence',
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame(['organization-created', 'organization-name-changed', 'organization-slug-changed'], $types);
    }

    public function testUnchangedSubmissionIsNoop(): void
    {
        $owner = $this->persistOwner('noop', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $organization = $manager->create($owner, 'acme', 'ACME Corp', null);

        $manager->edit($this->readModel('acme'), $owner, 'acme', 'ACME Corp', null);

        $count = $this->connection->fetchOne(
            'SELECT COUNT(*) FROM organization_event WHERE aggregateId = :id',
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame(1, (int) $count);
    }

    public function testEditRejectsReservedSlug(): void
    {
        $owner = $this->persistOwner('reserved', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $manager->create($owner, 'acme', 'ACME Corp', null);

        $this->expectException(InvalidSlugException::class);
        $manager->edit($this->readModel('acme'), $owner, 'composer', 'ACME Corp', null);
    }

    public function testEditRejectsSlugTakenByAnotherOrg(): void
    {
        $owner = $this->persistOwner('taker', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $manager->create($owner, 'acme', 'ACME Corp', null);
        $manager->create($owner, 'globex', 'Globex', null);

        $this->expectException(SlugTakenException::class);
        $manager->edit($this->readModel('acme'), $owner, 'globex', 'ACME Corp', null);
    }

    private function readModel(string $slug): ?Organization
    {
        return static::getService(OrganizationRepository::class)->findOneBySlug($slug);
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

        $em = static::getEM();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
