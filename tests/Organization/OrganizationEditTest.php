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
        $organization = $manager->create($owner, $owner, 'acme', 'ACME Corp', null);

        $manager->edit($this->readModel('acme'), $owner, 'acme', 'ACME Inc', '203.0.113.5');

        self::assertSame('ACME Inc', $this->readModel('acme')->displayName);

        // The rename is the latest event, appended after the creation batch.
        $type = $this->connection->fetchOne(
            'SELECT type FROM organization_event WHERE aggregateId = :id ORDER BY sequence DESC LIMIT 1',
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
        $organization = $manager->create($owner, $owner, 'acme', 'ACME Corp', null);

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
        $organization = $manager->create($owner, $owner, 'acme', 'ACME Corp', null);

        $manager->edit($this->readModel('acme'), $owner, 'acme-inc', 'ACME Inc', null);

        // The two edit events are appended after the creation batch, in the order they were made.
        $types = $this->connection->fetchFirstColumn(
            "SELECT type FROM organization_event WHERE aggregateId = :id AND type IN ('organization-name-changed', 'organization-slug-changed') ORDER BY sequence",
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame(['organization-name-changed', 'organization-slug-changed'], $types);
    }

    public function testUnchangedSubmissionIsNoop(): void
    {
        $owner = $this->persistOwner('noop', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $organization = $manager->create($owner, $owner, 'acme', 'ACME Corp', null);

        $manager->edit($this->readModel('acme'), $owner, 'acme', 'ACME Corp', null);

        // A no-op edit records nothing, so no name/slug change events are appended.
        $editEvents = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM organization_event WHERE aggregateId = :id AND type IN ('organization-name-changed', 'organization-slug-changed')",
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame(0, (int) $editEvents);
    }

    public function testOrgCanReclaimItsOwnPreviousSlug(): void
    {
        $owner = $this->persistOwner('reclaimer', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $organization = $manager->create($owner, $owner, 'acme', 'ACME Corp', null);

        // acme -> acme-inc reserves "acme" for this org.
        $manager->edit($this->readModel('acme'), $owner, 'acme-inc', 'ACME Corp', null);

        // acme-inc -> acme must succeed: the org reclaims the slug it freed.
        $manager->edit($this->readModel('acme-inc'), $owner, 'acme', 'ACME Corp', null);

        self::assertNull($this->readModel('acme-inc'));
        self::assertNotNull($this->readModel('acme'));

        // Only "acme-inc" is now actively reserved; the reclaimed "acme" reservation is released.
        $active = $this->connection->fetchAllAssociative(
            'SELECT slug FROM slug_reservation WHERE orgId = :id AND releasedAt IS NULL ORDER BY slug',
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame([['slug' => 'acme-inc']], $active);

        // The "acme" reservation row is preserved for the audit trail, just released.
        $released = $this->connection->fetchAllAssociative(
            'SELECT slug FROM slug_reservation WHERE orgId = :id AND releasedAt IS NOT NULL ORDER BY slug',
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame([['slug' => 'acme']], $released);
    }

    public function testEditRejectsSlugReservedByAnotherOrg(): void
    {
        $owner = $this->persistOwner('rivals', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $manager->create($owner, $owner, 'acme', 'ACME Corp', null);
        $manager->create($owner, $owner, 'globex', 'Globex', null);

        // globex renames away, freeing and reserving "globex" against itself.
        $manager->edit($this->readModel('globex'), $owner, 'globex-corp', 'Globex', null);

        // acme cannot claim "globex" while another org holds the active reservation.
        $this->expectException(SlugTakenException::class);
        $manager->edit($this->readModel('acme'), $owner, 'globex', 'ACME Corp', null);
    }

    public function testEditRejectsReservedSlug(): void
    {
        $owner = $this->persistOwner('reserved', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $manager->create($owner, $owner, 'acme', 'ACME Corp', null);

        $this->expectException(InvalidSlugException::class);
        $manager->edit($this->readModel('acme'), $owner, 'composer', 'ACME Corp', null);
    }

    public function testEditRejectsSlugTakenByAnotherOrg(): void
    {
        $owner = $this->persistOwner('taker', twoFactor: true);
        $manager = static::getService(OrganizationManager::class);
        $manager->create($owner, $owner, 'acme', 'ACME Corp', null);
        $manager->create($owner, $owner, 'globex', 'Globex', null);

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
