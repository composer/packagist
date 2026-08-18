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

namespace App\Tests\Audit;

use App\Audit\AuditRecordType;
use App\Entity\AuditRecord;
use App\Entity\User;
use App\Entity\UserFreezeReason;
use PHPUnit\Framework\TestCase;

class UserAuditRecordTest extends TestCase
{
    public function testGithubLinkedWithUser(): void
    {
        $user = new User();
        $user->setUsername('testuser');
        $user->setEmail('test@example.com');
        $user->setPassword('password');

        $reflection = new \ReflectionClass($user);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($user, 123);

        $actor = new User();
        $actor->setUsername('actoruser');
        $actor->setEmail('test@example.com');
        $actor->setPassword('password');

        $reflection = new \ReflectionClass($actor);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($actor, 234);

        $record = AuditRecord::gitHubLinkedWithUser($user, $actor, 'github-testuser', 123456);

        self::assertSame(AuditRecordType::GitHubLinkedWithUser, $record->type);
        self::assertSame('testuser', $record->attributes['user']['username']);
        self::assertSame('github-testuser', $record->attributes['github_username']);
        self::assertIsArray($record->attributes['actor']);
        self::assertSame(234, $record->attributes['actor']['id']);
        self::assertSame('actoruser', $record->attributes['actor']['username']);
        self::assertSame(234, $record->actorId);
        self::assertSame(123, $record->userId);
        self::assertSame(123456, $record->attributes['github_id']);
    }

    public function testGithubDisconnectedFromUser(): void
    {
        $user = new User();
        $user->setUsername('testuser');
        $user->setEmail('test@example.com');
        $user->setPassword('password');

        $reflection = new \ReflectionClass($user);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($user, 456);

        $actor = new User();
        $actor->setUsername('actoruser');
        $actor->setEmail('actor@example.com');
        $actor->setPassword('actor password');

        $reflection = new \ReflectionClass($actor);
        $idProperty = $reflection->getProperty('id');
        $idProperty->setValue($actor, 567);

        $record = AuditRecord::gitHubDisconnectedFromUser($user, $actor);

        self::assertSame(AuditRecordType::GitHubDisconnectedFromUser, $record->type);
        self::assertSame('testuser', $record->attributes['user']['username']);
        self::assertArrayNotHasKey('github_username', $record->attributes);
        self::assertIsArray($record->attributes['actor']);
        self::assertSame(567, $record->attributes['actor']['id']);
        self::assertSame('actoruser', $record->attributes['actor']['username']);
        self::assertSame(567, $record->actorId);
        self::assertSame(456, $record->userId);
    }

    public function testUserFrozen(): void
    {
        $user = $this->makeUser('baduser', 123);
        $actor = $this->makeUser('admin', 456);

        $record = AuditRecord::userFrozen($user, $actor, UserFreezeReason::Spam, 'spamming packages', 'ticket #42');

        self::assertSame(AuditRecordType::UserFrozen, $record->type);
        self::assertSame('baduser', $record->attributes['user']['username']);
        self::assertSame('spam', $record->attributes['reason']);
        self::assertSame('spamming packages', $record->attributes['reasonText']);
        self::assertSame('ticket #42', $record->attributes['internalReason']);
        self::assertIsArray($record->attributes['actor']);
        self::assertSame(456, $record->attributes['actor']['id']);
        self::assertSame(456, $record->actorId);
        self::assertSame(123, $record->userId);
    }

    public function testUserUnfrozen(): void
    {
        $user = $this->makeUser('reformed', 123);
        $actor = $this->makeUser('admin', 456);

        $record = AuditRecord::userUnfrozen($user, $actor, 'appeal accepted');

        self::assertSame(AuditRecordType::UserUnfrozen, $record->type);
        self::assertSame('reformed', $record->attributes['user']['username']);
        self::assertArrayNotHasKey('reason', $record->attributes);
        self::assertSame('appeal accepted', $record->attributes['reasonText']);
        self::assertNull($record->attributes['internalReason']);
        self::assertSame(456, $record->actorId);
        self::assertSame(123, $record->userId);
    }

    private function makeUser(string $username, int $id): User
    {
        $user = new User();
        $user->setUsername($username);
        $user->setEmail($username.'@example.com');
        $user->setPassword('password');

        new \ReflectionProperty($user, 'id')->setValue($user, $id);

        return $user;
    }
}
