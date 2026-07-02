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

namespace App\Tests\Entity;

use App\Audit\UserRegistrationMethod;
use App\Entity\AuditRecord;
use App\QueryFilter\AuditLog\PackageNameFilter;
use App\QueryFilter\AuditLog\UserFilter;
use App\QueryFilter\QueryFilterInterface;
use App\Tests\IntegrationTestCase;
use Symfony\Component\HttpFoundation\InputBag;

class AuditLogSearchTest extends IntegrationTestCase
{
    /**
     * @return list<AuditRecord>
     */
    private function runFilter(QueryFilterInterface $filter): array
    {
        $qb = $this->getEM()->getRepository(AuditRecord::class)
            ->createQueryBuilder('a')
            ->orderBy('a.id', 'DESC');
        $filter->filter($qb);

        /** @var list<AuditRecord> $result */
        $result = $qb->getQuery()->getResult();

        return $result;
    }

    /**
     * @return list<array{type: string, name: string}>
     */
    private function searchRows(AuditRecord $record): array
    {
        /** @var list<array{type: string, name: string}> $rows */
        $rows = $this->getEM()->getConnection()->fetchAllAssociative(
            'SELECT type, name FROM audit_log_search WHERE auditLogId = ?',
            [$record->id->toBinary()],
        );

        return $rows;
    }

    /**
     * @param list<AuditRecord> $haystack
     */
    private function assertContainsRecord(AuditRecord $needle, array $haystack): void
    {
        $ids = array_map(static fn (AuditRecord $record): string => $record->id->toRfc4122(), $haystack);
        $this->assertContains($needle->id->toRfc4122(), $ids);
    }

    public function testDirectInsertPathPopulatesIndex(): void
    {
        $user = self::createUser('naderman', 'nader@example.org');
        self::store($user);

        $record = AuditRecord::userCreated($user, UserRegistrationMethod::REGISTRATION_FORM);
        $this->getEM()->getRepository(AuditRecord::class)->insert($record);

        $this->assertEqualsCanonicalizing(
            [['type' => 'user', 'name' => 'naderman']],
            $this->searchRows($record),
        );
    }

    public function testOrmPersistPathPopulatesIndex(): void
    {
        $user = self::createUser('naderman', 'nader@example.org');
        self::store($user);

        $record = AuditRecord::passwordChanged($user, $user);
        $this->getEM()->persist($record);
        $this->getEM()->flush();

        $this->assertEqualsCanonicalizing(
            [
                ['type' => 'user', 'name' => 'naderman'],
                ['type' => 'actor', 'name' => 'naderman'],
            ],
            $this->searchRows($record),
        );
    }

    public function testUserFilterMatchesBothSidesOfARename(): void
    {
        $user = self::createUser('naderman', 'nader@example.org');
        self::store($user);

        $record = AuditRecord::usernameChanged($user, $user, 'oldnad');
        $this->getEM()->getRepository(AuditRecord::class)->insert($record);

        // The new handle finds the rename event (alongside the user's auto-created record)
        $matches = $this->runFilter(UserFilter::fromQuery(new InputBag(['user' => 'naderman']), 'user', false));
        $this->assertContainsRecord($record, $matches);

        // Only the rename event indexes the previous handle — case-insensitively
        $matches = $this->runFilter(UserFilter::fromQuery(new InputBag(['user' => 'OldNad']), 'user', false));
        $this->assertCount(1, $matches);
        $this->assertContainsRecord($record, $matches);
    }

    public function testPackageFilterFindsRecordsAcrossDeleteAndRecreate(): void
    {
        $repo = $this->getEM()->getRepository(AuditRecord::class);

        // An old package deleted long ago...
        $oldPackage = self::createPackage('acme/widget', 'https://github.com/acme/widget');
        new \ReflectionProperty($oldPackage, 'id')->setValue($oldPackage, 100);
        $repo->insert(AuditRecord::packageDeleted($oldPackage, null));

        // ...and a new package later re-created under the same name (a different package id)
        $newPackage = self::createPackage('acme/widget', 'https://github.com/acme/widget-fork');
        new \ReflectionProperty($newPackage, 'id')->setValue($newPackage, 200);
        $repo->insert(AuditRecord::packageCreated($newPackage, null));

        $matches = $this->runFilter(PackageNameFilter::fromQuery(new InputBag(['package' => 'Acme/Widget']), 'package', false));
        $this->assertCount(2, $matches);
    }
}
