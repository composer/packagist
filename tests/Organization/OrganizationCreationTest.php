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
use App\Organization\Domain\Exception\InvalidDisplayNameException;
use App\Organization\Domain\Exception\InvalidSlugException;
use App\Organization\Domain\Exception\SlugTakenException;
use App\Organization\Domain\Exception\TwoFactorRequiredException;
use App\Organization\OrganizationManager;
use Doctrine\DBAL\Connection;
use Doctrine\Persistence\ManagerRegistry;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

class OrganizationCreationTest extends KernelTestCase
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

    public function testCreatePersistsEventProjectionAndTransparencyLog(): void
    {
        $owner = $this->persistOwner('orgowner', twoFactor: true);

        $manager = static::getContainer()->get(OrganizationManager::class);
        $organization = $manager->create($owner, 'acme', 'ACME Corp', '203.0.113.5');

        self::assertSame('acme', $organization->slug());

        // Read-model projection.
        $readModel = static::getContainer()->get(OrganizationRepository::class)->findOneBySlug('acme');
        self::assertNotNull($readModel);
        self::assertSame('ACME Corp', $readModel->displayName);
        self::assertSame($owner->getId(), $readModel->createdBy?->getId());
        self::assertFalse($readModel->isDeleted());

        // Canonical event stream.
        $event = $this->connection->fetchAssociative(
            'SELECT type, sequence, actorLabel, actorRoleInOrg FROM organization_event WHERE aggregateId = :id',
            ['id' => $organization->id->toBinary()],
        );
        self::assertNotFalse($event);
        self::assertSame('organization-created', $event['type']);
        self::assertSame(1, (int) $event['sequence']);
        self::assertSame('user', $event['actorLabel']);
        self::assertSame('owner', $event['actorRoleInOrg']);

        // Transparency log projection.
        $auditCount = $this->connection->fetchOne(
            "SELECT COUNT(*) FROM audit_log WHERE type = 'organization_created' AND actorId = :actor",
            ['actor' => $owner->getId()],
        );
        self::assertSame(1, (int) $auditCount);
    }

    public function testCreateRejectsReservedSlug(): void
    {
        $owner = $this->persistOwner('reserved', twoFactor: true);

        $this->expectException(InvalidSlugException::class);

        static::getContainer()->get(OrganizationManager::class)
            ->create($owner, 'composer', 'Composer', null);
    }

    public function testCreateRejectsReservedDisplayName(): void
    {
        $owner = $this->persistOwner('user', twoFactor: true);

        $this->expectException(InvalidDisplayNameException::class);

        // Valid, claimable slug so the display-name deny-list is what trips (case-insensitive).
        static::getContainer()->get(OrganizationManager::class)
            ->create($owner, 'acme', 'PHP', null);
    }

    public function testCreateRejectsAlreadyTakenSlug(): void
    {
        $first = $this->persistOwner('first', twoFactor: true);
        $second = $this->persistOwner('second', twoFactor: true);

        $manager = static::getContainer()->get(OrganizationManager::class);
        $manager->create($first, 'acme', 'ACME Corp', null);

        $this->expectException(SlugTakenException::class);
        $manager->create($second, 'acme', 'ACME Two', null);
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
