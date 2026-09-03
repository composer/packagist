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

use App\Entity\AuditRecord;
use App\Entity\Package;
use App\Entity\PackageTransparencyLog;
use App\Entity\PackageTransparencyLogSearchRepository;
use App\Log\TransparencyLogEventType;
use App\Tests\IntegrationTestCase;
use Doctrine\DBAL\Connection;

class PackageTransparencyLogSearchRepositoryTest extends IntegrationTestCase
{
    public function testIndexesTheSubjectAndTheActorUnderTheirRolesAndRecordedNames(): void
    {
        $entry = $this->entry(TransparencyLogEventType::MaintainerAdded, [
            'name' => 'ptl/index',
            'user' => ['id' => 11, 'username' => 'Subject'],
            'actor' => ['id' => 22, 'username' => 'Moderator'],
        ]);

        self::assertSame(2, self::repository()->index([$entry]));
        // lowercase, because the search is not case-sensitive
        self::assertSame([
            ['type' => 'user', 'name' => 'subject', 'userId' => 11, 'primaryRow' => true],
            ['type' => 'actor', 'name' => 'moderator', 'userId' => 22, 'primaryRow' => true],
        ], $this->indexedRows($entry->leafIndex));
    }

    /**
     * In most account events the person is both user and actor. Only one of the two rows is primary.
     */
    public function testSomeoneActingOnTheirOwnAccountHasOnePrimaryRow(): void
    {
        $entry = $this->entry(TransparencyLogEventType::PasswordChanged, [
            'user' => ['id' => 11, 'username' => 'self_acting'],
            'actor' => ['id' => 11, 'username' => 'self_acting'],
        ]);

        self::assertSame(2, self::repository()->index([$entry]));
        self::assertSame([
            ['type' => 'user', 'name' => 'self_acting', 'userId' => 11, 'primaryRow' => true],
            ['type' => 'actor', 'name' => 'self_acting', 'userId' => 11, 'primaryRow' => false],
        ], $this->indexedRows($entry->leafIndex));
    }

    /**
     * The maintainers of a transfer are indexed as users.
     */
    public function testIndexesEveryMaintainerNamedByATransferAsAUser(): void
    {
        $entry = $this->entry(TransparencyLogEventType::PackageTransferred, [
            'name' => 'ptl/transferred',
            'actor' => ['id' => 22, 'username' => 'admin_user'],
            'previous_maintainers' => [['id' => 11, 'username' => 'gone'], ['id' => 12, 'username' => 'stayed']],
            'current_maintainers' => [['id' => 12, 'username' => 'stayed'], ['id' => 13, 'username' => 'arrived']],
        ]);

        self::assertSame(4, self::repository()->index([$entry]));
        self::assertSame([
            ['type' => 'user', 'name' => 'gone', 'userId' => 11, 'primaryRow' => true],
            ['type' => 'user', 'name' => 'stayed', 'userId' => 12, 'primaryRow' => true],
            ['type' => 'user', 'name' => 'arrived', 'userId' => 13, 'primaryRow' => true],
            ['type' => 'actor', 'name' => 'admin_user', 'userId' => 22, 'primaryRow' => true],
        ], $this->indexedRows($entry->leafIndex));
    }

    /**
     * A person is indexed only if both the id and the username are known.
     */
    public function testSkipsPeopleMissingEitherLabel(): void
    {
        $entry = $this->entry(TransparencyLogEventType::PackageDeleted, [
            'name' => 'ptl/automated',
            'user' => ['username' => 'no_id_recorded'],
            'actor' => 'automation',
        ]);

        self::assertSame(0, self::repository()->index([$entry]));
        self::assertSame([], $this->indexedRows($entry->leafIndex));
    }

    public function testAnEntryNamingNobodyWritesNothing(): void
    {
        $entry = $this->entry(TransparencyLogEventType::VersionCreated, [
            'name' => 'ptl/versioned',
            'version' => '1.0.0',
        ]);

        self::assertSame(0, self::repository()->index([$entry]));
        self::assertSame([], $this->indexedRows($entry->leafIndex));
    }

    /**
     * The backfill and the projector can index the same entry.
     */
    public function testReindexingAnEntryChangesNothing(): void
    {
        $entry = $this->entry(TransparencyLogEventType::MaintainerRemoved, [
            'name' => 'ptl/reindexed',
            'user' => ['id' => 11, 'username' => 'subject'],
        ]);

        self::assertSame(1, self::repository()->index([$entry]));
        self::assertSame(0, self::repository()->index([$entry]));
        self::assertSame([
            ['type' => 'user', 'name' => 'subject', 'userId' => 11, 'primaryRow' => true],
        ], $this->indexedRows($entry->leafIndex));
    }

    /**
     * The filters sort by this copy of the event time.
     */
    public function testCopiesTheEntrysEventTimeForOrdering(): void
    {
        $entry = $this->entry(TransparencyLogEventType::MaintainerAdded, [
            'name' => 'ptl/ordered',
            'user' => ['id' => 11, 'username' => 'subject'],
        ]);

        self::repository()->index([$entry]);

        self::assertSame(
            $entry->datetime->format('Y-m-d H:i:s'),
            (string) self::getService(Connection::class)->fetchOne(
                'SELECT datetime FROM package_transparency_log_search WHERE leafIndex = ?',
                [$entry->leafIndex],
            ),
        );
    }

    /**
     * One record gives one entry per package, and they are indexed together.
     */
    public function testIndexesSeveralEntriesInOneCall(): void
    {
        $attributes = ['user' => ['id' => 11, 'username' => 'subject'], 'actor' => ['id' => 22, 'username' => 'moderator']];
        $first = $this->entry(TransparencyLogEventType::PasswordChanged, $attributes);
        $second = $this->entry(TransparencyLogEventType::PasswordChanged, $attributes, $first->leafIndex + 1);

        self::assertSame(4, self::repository()->index([$first, $second]));
        self::assertCount(2, $this->indexedRows($first->leafIndex));
        self::assertCount(2, $this->indexedRows($second->leafIndex));
    }

    public function testIndexingNoEntriesWritesNothing(): void
    {
        self::assertSame(0, self::repository()->index([]));
    }

    public function testFilterIndexedLeafIndexesReportsOnlyTheIndexedOnes(): void
    {
        $indexed = $this->entry(TransparencyLogEventType::MaintainerAdded, [
            'name' => 'ptl/filtered',
            'user' => ['id' => 11, 'username' => 'subject'],
        ]);
        self::repository()->index([$indexed]);

        self::assertSame([$indexed->leafIndex], self::repository()->filterIndexedLeafIndexes([$indexed->leafIndex, $indexed->leafIndex + 1]));
        self::assertSame([], self::repository()->filterIndexedLeafIndexes([]));
    }

    /**
     * @param array<string, mixed> $attributes
     */
    private function entry(TransparencyLogEventType $type, array $attributes, int $leafIndex = 9_000_000): PackageTransparencyLog
    {
        $package = new Package();
        $package->setName('ptl/indexed');
        new \ReflectionProperty($package, 'id')->setValue($package, 1);
        new \ReflectionProperty($package, 'repository')->setValue($package, 'https://github.com/ptl/indexed');

        return PackageTransparencyLog::project(
            AuditRecord::packageCreated($package, null),
            $type,
            // the index has no foreign key, so the entry does not have to be stored
            $leafIndex,
            $attributes,
            1,
            'ptl',
            'ptl/indexed',
        );
    }

    /**
     * Users first, then the actor, the same order as getSearchTerms().
     *
     * @return list<array{type: string, name: string, userId: int, primaryRow: bool}>
     */
    private function indexedRows(int $leafIndex): array
    {
        $rows = self::getService(Connection::class)->fetchAllAssociative(
            "SELECT type, name, userId, primaryRow FROM package_transparency_log_search WHERE leafIndex = ? ORDER BY type = 'actor', userId, name",
            [$leafIndex],
        );

        return array_map(static fn (array $row): array => [
            'type' => (string) $row['type'],
            'name' => (string) $row['name'],
            'userId' => (int) $row['userId'],
            'primaryRow' => (bool) $row['primaryRow'],
        ], $rows);
    }

    private static function repository(): PackageTransparencyLogSearchRepository
    {
        return self::getService(PackageTransparencyLogSearchRepository::class);
    }
}
