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

use App\Package\Git\GitObject;
use App\Package\Git\ObjectFormat;
use App\Package\Git\ObjectType;
use App\Package\Git\ProtocolException;
use App\Package\Git\UnsupportedRepositoryException;
use PHPUnit\Framework\TestCase;

class GitObjectTest extends TestCase
{
    public function testParseCommit(): void
    {
        $tree = str_repeat('a', 40);
        $commit = new GitObject(ObjectType::Commit, "tree $tree\nparent ".str_repeat('b', 40)."\nauthor Jane <jane@example.org> 1700000000 +0200\ncommitter Joe <joe@example.org> 1800000000 +0000\n\nauthor Fake <x> 1 +0000\n");

        self::assertSame(['tree' => $tree, 'authorTime' => 1700000000], $commit->parseCommit(ObjectFormat::Sha1));
    }

    public function testParseCommitWithoutAuthor(): void
    {
        $commit = new GitObject(ObjectType::Commit, 'tree '.str_repeat('a', 64)."\n\nmessage");

        self::assertNull($commit->parseCommit(ObjectFormat::Sha256)['authorTime']);
    }

    public function testParseCommitRejectsTreeOfTheWrongFormat(): void
    {
        $this->expectException(ProtocolException::class);

        new GitObject(ObjectType::Commit, 'tree '.str_repeat('a', 40)."\n")->parseCommit(ObjectFormat::Sha256);
    }

    public function testParseCommitRejectsOtherTypes(): void
    {
        $this->expectException(ProtocolException::class);

        new GitObject(ObjectType::Blob, 'tree '.str_repeat('a', 40)."\n")->parseCommit(ObjectFormat::Sha1);
    }

    public function testParseTree(): void
    {
        $file = random_bytes(20);
        $dir = random_bytes(20);
        $tree = new GitObject(ObjectType::Tree, "100644 composer.json\0".$file."40000 src dir\0".$dir);

        self::assertSame([
            'composer.json' => ['mode' => '100644', 'oid' => bin2hex($file)],
            'src dir' => ['mode' => '40000', 'oid' => bin2hex($dir)],
        ], $tree->parseTree(ObjectFormat::Sha1));
    }

    public function testParseTreeRejectsTruncatedEntries(): void
    {
        $this->expectException(ProtocolException::class);

        new GitObject(ObjectType::Tree, "100644 composer.json\0".random_bytes(10))->parseTree(ObjectFormat::Sha1);
    }

    public function testObjectFormat(): void
    {
        self::assertSame(ObjectFormat::Sha1, ObjectFormat::fromCapability(null));
        self::assertSame(ObjectFormat::Sha256, ObjectFormat::fromCapability('sha256'));
        self::assertTrue(ObjectFormat::Sha256->isObjectId(str_repeat('a', 64)));
        self::assertFalse(ObjectFormat::Sha256->isObjectId(str_repeat('a', 40)));
        self::assertFalse(ObjectFormat::Sha1->isObjectId(str_repeat('A', 40)));
        // well known id of the empty blob
        self::assertSame('e69de29bb2d1d6434b8b29ae775ad8c2e48c5391', ObjectFormat::Sha1->hashObject(ObjectType::Blob, ''));

        $this->expectException(UnsupportedRepositoryException::class);
        ObjectFormat::fromCapability('sha512');
    }
}
