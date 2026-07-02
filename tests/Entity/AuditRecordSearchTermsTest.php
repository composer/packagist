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
use App\Entity\Package;
use App\Entity\User;
use App\Tests\Fixtures\Fixtures;
use PHPUnit\Framework\TestCase;

class AuditRecordSearchTermsTest extends TestCase
{
    use Fixtures;

    /**
     * The audit factories snapshot getId(), which throws on an unpersisted entity, so give the
     * fixtures an id. The value is irrelevant to search terms (only names are indexed).
     */
    private static function user(string $username, int $id): User
    {
        $user = self::createUser($username, $id.'@example.org');
        new \ReflectionProperty($user, 'id')->setValue($user, $id);

        return $user;
    }

    private static function package(string $name, int $id): Package
    {
        $package = self::createPackage($name, 'https://github.com/'.$name);
        new \ReflectionProperty($package, 'id')->setValue($package, $id);

        return $package;
    }

    /**
     * @param list<array{type: string, name: string}> $terms
     *
     * @return list<string>
     */
    private static function flatten(array $terms): array
    {
        return array_map(static fn (array $term): string => $term['type'].':'.$term['name'], $terms);
    }

    public function testUserCreatedIndexesUsernameLowercased(): void
    {
        $record = AuditRecord::userCreated(self::user('Naderman', 1), UserRegistrationMethod::REGISTRATION_FORM);

        // the 'self' actor sentinel is not indexed
        $this->assertSame(['user:naderman'], self::flatten($record->getSearchTerms()));
    }

    public function testPackageCreatedIndexesPackageAndActorLowercased(): void
    {
        $record = AuditRecord::packageCreated(self::package('Acme/Widget', 1), self::user('AdminUser', 2));

        $this->assertEqualsCanonicalizing(
            ['package:acme/widget', 'actor:adminuser'],
            self::flatten($record->getSearchTerms()),
        );
    }

    public function testMaintainerAddedIndexesPackageUserAndActor(): void
    {
        $record = AuditRecord::maintainerAdded(self::package('acme/widget', 1), self::user('Maintainer', 2), self::user('AdminUser', 3));

        $this->assertEqualsCanonicalizing(
            ['package:acme/widget', 'user:maintainer', 'actor:adminuser'],
            self::flatten($record->getSearchTerms()),
        );
    }

    public function testPackageTransferredIndexesMaintainerArraysAndSkipsStringActor(): void
    {
        // a null actor is stored as the 'admin' string sentinel, which must not be indexed
        $record = AuditRecord::packageTransferred(
            self::package('acme/widget', 1),
            null,
            [self::user('OldOwner', 2)],
            [self::user('NewOwner', 3)],
        );

        $this->assertEqualsCanonicalizing(
            ['package:acme/widget', 'user:oldowner', 'user:newowner'],
            self::flatten($record->getSearchTerms()),
        );
    }

    public function testUsernameChangedIndexesBothOldAndNewHandles(): void
    {
        $record = AuditRecord::usernameChanged(self::user('Naderman', 1), self::user('AdminUser', 2), 'OldNad');

        // user.username + username_to collapse to 'naderman'; username_from adds 'oldnad'
        $this->assertEqualsCanonicalizing(
            ['user:naderman', 'user:oldnad', 'actor:adminuser'],
            self::flatten($record->getSearchTerms()),
        );
    }
}
