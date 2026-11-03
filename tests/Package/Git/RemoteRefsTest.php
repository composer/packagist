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

namespace App\Tests\Package\Git;

use App\Package\Git\ObjectFormat;
use App\Package\Git\ProtocolException;
use App\Package\Git\RemoteRefs;
use PHPUnit\Framework\TestCase;

class RemoteRefsTest extends TestCase
{
    public function testFromRefList(): void
    {
        [$main, $feature, $tagObject, $tagCommit] = [str_repeat('1', 40), str_repeat('2', 40), str_repeat('3', 40), str_repeat('4', 40)];

        $refs = RemoteRefs::fromRefList([
            ['oid' => $main, 'name' => 'HEAD', 'peeled' => null, 'symrefTarget' => 'refs/heads/main'],
            ['oid' => $main, 'name' => 'refs/heads/main', 'peeled' => null, 'symrefTarget' => null],
            ['oid' => $feature, 'name' => 'refs/heads/2.x', 'peeled' => null, 'symrefTarget' => null],
            ['oid' => $tagObject, 'name' => 'refs/tags/v1.0.0', 'peeled' => $tagCommit, 'symrefTarget' => null],
            ['oid' => $main, 'name' => 'refs/tags/123', 'peeled' => null, 'symrefTarget' => null],
            ['oid' => $feature, 'name' => 'refs/pull/1/head', 'peeled' => null, 'symrefTarget' => null],
        ], ObjectFormat::Sha1);

        self::assertSame(['main' => $main, '2.x' => $feature], $refs->branches);
        self::assertSame(['v1.0.0' => $tagCommit, 123 => $main], $refs->tags);
        self::assertSame('main', $refs->defaultBranch);
        self::assertSame([$main => $main, $feature => $feature, $tagCommit => $tagObject], $refs->advertisedTips);
    }

    public function testDefaultBranchIsNullWhenHeadPointsToAMissingBranch(): void
    {
        $refs = RemoteRefs::fromRefList([
            ['oid' => str_repeat('1', 40), 'name' => 'HEAD', 'peeled' => null, 'symrefTarget' => 'refs/heads/master'],
            ['oid' => str_repeat('1', 40), 'name' => 'refs/heads/main', 'peeled' => null, 'symrefTarget' => null],
        ], ObjectFormat::Sha1);

        self::assertNull($refs->defaultBranch);
    }

    public function testRejectsIdsOfTheWrongFormat(): void
    {
        $this->expectException(ProtocolException::class);

        RemoteRefs::fromRefList([['oid' => str_repeat('1', 40), 'name' => 'refs/heads/main', 'peeled' => null, 'symrefTarget' => null]], ObjectFormat::Sha256);
    }
}
