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

use App\Entity\OrganizationRepository;
use App\Entity\User;
use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Exception\SlugTakenException;
use App\Organization\OrganizationManager;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;

class OrganizationCreationTest extends IntegrationTestCase
{
    public function testCreatePersistsEventProjectionAndTransparencyLog(): void
    {
        $connection = static::getService(Connection::class);
        $owner = $this->persistOwner('orgowner', twoFactor: true);

        $manager = static::getService(OrganizationManager::class);
        $organization = $manager->create($owner, $owner, 'acme', 'ACME Corp', '203.0.113.5');

        self::assertSame('acme', $organization->slug());

        // Read-model projection.
        $readModel = static::getService(OrganizationRepository::class)->findOneBySlug('acme');
        self::assertNotNull($readModel);
        self::assertSame('ACME Corp', $readModel->displayName);
        self::assertFalse($readModel->isDeleted());

        // Canonical event stream: the org, the founding owner joining, its two system teams, then the
        // owner's membership of each, in sequence.
        $events = $connection->fetchAllAssociative(
            'SELECT type, sequence, actorLabel FROM organization_event WHERE aggregateId = :id ORDER BY sequence',
            ['id' => $organization->id->toBinary()],
        );
        self::assertSame(
            ['organization-created', 'member-joined', 'team-created', 'team-created', 'team-member-added', 'team-member-added'],
            array_column($events, 'type'),
        );
        self::assertSame([1, 2, 3, 4, 5, 6], array_map('intval', array_column($events, 'sequence')));
        self::assertSame(['user'], array_values(array_unique(array_column($events, 'actorLabel'))));

        // Transparency log projection.
        $auditCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE type = 'organization_created' AND actorId = :actor",
            ['actor' => $owner->getId()],
        );
        self::assertSame(1, (int) $auditCount);

        // Both system teams are logged as created.
        $createdTeams = $connection->fetchFirstColumn(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.team_name')) FROM audit_log
             WHERE type = 'organization_team_created' AND actorId = :actor
             ORDER BY 1",
            ['actor' => $owner->getId()],
        );
        self::assertSame(['All organization members', 'Owners'], $createdTeams);

        // The creator joins both system teams as part of creation; each join is logged.
        $joinedTeams = $connection->fetchFirstColumn(
            "SELECT JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.team_name')) FROM audit_log
             WHERE type = 'organization_team_member_added' AND actorId = :actor AND userId = :member
             ORDER BY 1",
            ['actor' => $owner->getId(), 'member' => $owner->getId()],
        );
        self::assertSame(['All organization members', 'Owners'], $joinedTeams);

        // The founding owner's join is published once as an org-level member-joined entry (the teams
        // they land in are the separate team-member-added entries asserted above).
        $memberJoined = $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log
             WHERE type = 'organization_member_joined' AND actorId = :actor AND userId = :member",
            ['actor' => $owner->getId(), 'member' => $owner->getId()],
        );
        self::assertSame(1, (int) $memberJoined);

        // The read model is not double-added: the owner has exactly one row per system team.
        $ownerTeamRows = $connection->fetchOne(
            'SELECT COUNT(*) FROM organization_team_member WHERE userId = :member',
            ['member' => $owner->getId()],
        );
        self::assertSame(2, (int) $ownerTeamRows);
    }

    public function testCreateRejectsReservedSlug(): void
    {
        $owner = $this->persistOwner('reserved', twoFactor: true);

        $this->expectException(InvalidSlugException::class);

        static::getService(OrganizationManager::class)
            ->create($owner, $owner, 'composer', 'Composer', null);
    }

    public function testCreateRejectsAlreadyTakenSlug(): void
    {
        $first = $this->persistOwner('first', twoFactor: true);
        $second = $this->persistOwner('second', twoFactor: true);

        $manager = static::getService(OrganizationManager::class);
        $manager->create($first, $first, 'acme', 'ACME Corp', null);

        $this->expectException(SlugTakenException::class);
        $manager->create($second, $second, 'acme', 'ACME Two', null);
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
