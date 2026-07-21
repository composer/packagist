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
use App\Entity\OrganizationTeamMemberRepository;
use App\Entity\OrganizationTeamRepository;
use App\Entity\User;
use App\Organization\Domain\Exception\LastOwnerProtectedException;
use App\Organization\Domain\Exception\TeamNameTakenException;
use App\Organization\Domain\Exception\TeamProtectedException;
use App\Organization\Domain\OrganizationTeamKind;
use App\Organization\OrganizationManager;
use App\Organization\OrganizationMembershipManager;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;

class OrganizationMembershipTest extends IntegrationTestCase
{
    public function testCreationBootstrapsOwnersAndAllMembersTeamsWithCreator(): void
    {
        $owner = $this->persistOwner('orgowner');
        $organization = $this->createOrg($owner, 'acme');

        $readModel = static::getService(OrganizationRepository::class)->findOneBySlug('acme');
        self::assertNotNull($readModel);
        self::assertNotNull($readModel->ownersTeamId);
        self::assertNotNull($readModel->allMembersTeamId);

        $teams = static::getService(OrganizationTeamRepository::class)->findByOrg($organization->id);
        self::assertCount(2, $teams);

        $byTeamId = [];
        foreach ($teams as $team) {
            self::assertSame(OrganizationTeamKind::System, $team->kind);
            $byTeamId[$team->teamId->toRfc4122()] = $team;
        }

        $ownersTeam = $byTeamId[$readModel->ownersTeamId->toRfc4122()] ?? null;
        $allMembersTeam = $byTeamId[$readModel->allMembersTeamId->toRfc4122()] ?? null;
        self::assertNotNull($ownersTeam);
        self::assertNotNull($allMembersTeam);
        self::assertSame('Owners', $ownersTeam->name);
        self::assertSame('All organization members', $allMembersTeam->name);

        $members = static::getService(OrganizationTeamMemberRepository::class);
        self::assertTrue($members->isOwner($readModel->ownersTeamId, $owner->getId()));
        self::assertTrue($members->isMemberOfOrg($organization->id, $owner->getId()));
        // The creator is seeded into the all-members team too.
        self::assertSame(1, $members->countByTeam($readModel->allMembersTeamId));
    }

    public function testCreateTeamPersistsRowAndTransparencyLog(): void
    {
        $connection = static::getService(Connection::class);
        $owner = $this->persistOwner('orgowner');
        $organization = $this->createOrg($owner, 'acme');

        $this->membership()->createTeam($this->readModel('acme'), $owner, 'backend', null);

        $teams = static::getService(OrganizationTeamRepository::class)->findByOrg($organization->id);
        $names = array_map(static fn ($t): string => $t->name, $teams);
        self::assertContains('backend', $names);

        // The two system teams are also logged as created, so scope the assertion to the custom team.
        $auditCount = $connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE type = 'organization_team_created' AND organizationId = :org
             AND JSON_UNQUOTE(JSON_EXTRACT(attributes, '$.team_name')) = 'backend'",
            ['org' => $organization->id->toBinary()],
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testDuplicateTeamNameIsRejected(): void
    {
        $owner = $this->persistOwner('orgowner');
        $this->createOrg($owner, 'acme');

        $this->membership()->createTeam($this->readModel('acme'), $owner, 'backend', null);

        $this->expectException(TeamNameTakenException::class);
        $this->membership()->createTeam($this->readModel('acme'), $owner, 'Backend', null);
    }

    public function testOwnersTeamCannotBeDeleted(): void
    {
        $owner = $this->persistOwner('orgowner');
        $organization = $this->createOrg($owner, 'acme');
        $ownersTeamId = $this->readModel('acme')->ownersTeamId;
        self::assertNotNull($ownersTeamId);

        $this->expectException(TeamProtectedException::class);
        $this->membership()->deleteTeam($this->readModel('acme'), $owner, $ownersTeamId, null);
    }

    public function testLastOwnerCannotLeave(): void
    {
        $owner = $this->persistOwner('orgowner');
        $this->createOrg($owner, 'acme');

        $this->expectException(LastOwnerProtectedException::class);
        $this->membership()->leave($this->readModel('acme'), $owner, null);
    }

    private function createOrg(User $owner, string $slug): \App\Organization\Domain\Organization
    {
        return static::getService(OrganizationManager::class)->create($owner, $owner, $slug, 'ACME Corp', null);
    }

    private function readModel(string $slug): \App\Entity\Organization
    {
        $organization = static::getService(OrganizationRepository::class)->findOneBySlug($slug);
        self::assertNotNull($organization);

        return $organization;
    }

    private function membership(): OrganizationMembershipManager
    {
        return static::getService(OrganizationMembershipManager::class);
    }

    private function persistOwner(string $username): User
    {
        $user = new User();
        $user->setEnabled(true);
        $user->setUsername($username);
        $user->setUsernameCanonical($username);
        $user->setEmail($username.'@example.org');
        $user->setEmailCanonical($username.'@example.org');
        $user->setPassword('testtest');
        $user->setTotpSecret('totp-secret');

        $em = static::getEM();
        $em->persist($user);
        $em->flush();

        return $user;
    }
}
