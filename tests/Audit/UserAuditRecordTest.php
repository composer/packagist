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

        $record = AuditRecord::gitHubLinkedWithUser($user, 'github-testuser');

        self::assertSame(AuditRecordType::GitHubLinkedWithUser, $record->type);
        self::assertSame('testuser', $record->attributes['user']['username']);
        self::assertSame('github-testuser', $record->attributes['github_username']);
        self::assertIsArray($record->attributes['actor']);
        self::assertSame(123, $record->attributes['actor']['id']);
        self::assertSame('testuser', $record->attributes['actor']['username']);
        self::assertSame(123, $record->actorId);
        self::assertSame(123, $record->userId);
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

        $record = AuditRecord::gitHubDisconnectedFromUser($user);

        self::assertSame(AuditRecordType::GitHubDisconnectedFromUser, $record->type);
        self::assertSame('testuser', $record->attributes['user']['username']);
        self::assertArrayNotHasKey('github_username', $record->attributes);
        self::assertIsArray($record->attributes['actor']);
        self::assertSame(456, $record->attributes['actor']['id']);
        self::assertSame('testuser', $record->attributes['actor']['username']);
        self::assertSame(456, $record->actorId);
        self::assertSame(456, $record->userId);
    }
}
