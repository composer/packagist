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

use App\Package\Git\LimitExceededException;
use App\Package\Git\ObjectFormat;
use App\Package\Git\ObjectType;
use App\Package\Git\PackParser;
use App\Package\Git\ProtocolException;
use Composer\Pcre\Preg;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Filesystem\Filesystem;

class PackParserTest extends TestCase
{
    private static string $tempDir;

    public static function setUpBeforeClass(): void
    {
        self::$tempDir = GitRepositoryBuilder::createTempDir('pack-parser-test');
    }

    public static function tearDownAfterClass(): void
    {
        new Filesystem()->remove(self::$tempDir);
    }

    #[DataProvider('provideGitPacks')]
    public function testParsesPacksWrittenByGit(string $objectFormat, bool $offsetDeltas): void
    {
        $builder = new GitRepositoryBuilder(self::$tempDir.'/'.$objectFormat, $objectFormat);
        // a large file changed slightly across commits so git stores deltas
        $big = str_repeat("some line of text that compresses well\n", 2000);
        for ($i = 1; $i <= 4; $i++) {
            $big .= "change $i\n";
            $builder->commit(['big.txt' => $big, 'composer.json' => '{"name":"acme/foo","v":'.$i.'}', 'dir/file.txt' => "file $i\n"]);
        }

        $objectList = $builder->git('rev-list', '--objects', '--all');
        $oids = array_map(static fn ($line) => explode(' ', $line)[0], array_filter(explode("\n", $objectList)));
        $packArgs = ['pack-objects', '-q', '--stdout', '--window=10', '--depth=5'];
        if ($offsetDeltas) {
            $packArgs[] = '--delta-base-offset';
        }
        $pack = GitRepositoryBuilder::run($packArgs, $builder->workDir, [], implode("\n", $oids)."\n");

        $objects = new PackParser(ObjectFormat::from($objectFormat))->parse($pack);

        // make sure the deltas this test is about are actually in the pack
        $packFile = $builder->workDir.'/test.pack';
        file_put_contents($packFile, $pack);
        GitRepositoryBuilder::run(['index-pack', '--object-format='.$objectFormat, $packFile], $builder->workDir);
        $deltaLines = array_filter(explode("\n", GitRepositoryBuilder::run(['verify-pack', '-v', $builder->workDir.'/test.idx'], $builder->workDir)), static fn ($line) => Preg::isMatch('{^[0-9a-f]+ +\w+ +\d+ \d+ \d+ \d+ [0-9a-f]+$}', $line));
        self::assertNotEmpty($deltaLines);
        self::assertSame($offsetDeltas, self::hasOffsetDelta($pack, ObjectFormat::from($objectFormat)));

        self::assertEqualsCanonicalizing($oids, array_keys($objects));
        foreach ($objects as $oid => $object) {
            self::assertSame(trim($builder->git('cat-file', '-t', $oid)), $object->type->label());
            if ($object->type === ObjectType::Blob) {
                self::assertSame($builder->git('cat-file', 'blob', $oid), $object->contents);
            }
        }
        self::assertStringContainsString("change 4\n", $objects[trim($builder->git('rev-parse', 'HEAD:big.txt'))]->contents);
    }

    /**
     * @return iterable<array{string, bool}>
     */
    public static function provideGitPacks(): iterable
    {
        yield 'sha1 offset deltas' => ['sha1', true];
        yield 'sha1 ref deltas' => ['sha1', false];
        yield 'sha256 offset deltas' => ['sha256', true];
        yield 'sha256 ref deltas' => ['sha256', false];
    }

    public function testResolvesRefDeltasAgainstLaterObjects(): void
    {
        $base = 'hello world, this is the base';
        $baseOid = ObjectFormat::Sha1->hashObject(ObjectType::Blob, $base);
        // copy the first 11 bytes of the base, then insert "!"
        $delta = self::deltaHeader(\strlen($base), 12)."\x90\x0B\x01!";

        $pack = self::buildPack([
            self::refDelta(hex2bin($baseOid), $delta),
            self::object(ObjectType::Blob->value, $base),
        ]);

        $objects = new PackParser(ObjectFormat::Sha1)->parse($pack);

        self::assertSame('hello world!', $objects[ObjectFormat::Sha1->hashObject(ObjectType::Blob, 'hello world!')]->contents);
    }

    #[DataProvider('provideInvalidPacks')]
    public function testRejectsInvalidPacks(\Closure $pack, string $exception, string $message): void
    {
        $this->expectException($exception);
        $this->expectExceptionMessage($message);

        new PackParser(ObjectFormat::Sha1, maxObjects: 10, maxObjectSize: 1000, maxTotalSize: 1500)->parse($pack());
    }

    /**
     * @return iterable<array{\Closure(): string, class-string<\Throwable>, string}>
     */
    public static function provideInvalidPacks(): iterable
    {
        $valid = static fn () => self::buildPack([self::object(ObjectType::Blob->value, 'contents')]);

        yield 'not a pack' => [static fn () => str_repeat('x', 40), ProtocolException::class, 'Not a pack file'];
        yield 'bad version' => [static fn () => self::buildPack([], 4), ProtocolException::class, 'Unsupported pack version 4'];
        yield 'checksum mismatch' => [static fn () => substr_replace($valid(), 'X', 14, 1), ProtocolException::class, 'Pack checksum mismatch'];
        yield 'too many objects' => [static fn () => self::buildPack([], 2, 11), LimitExceededException::class, 'Pack contains 11 objects'];
        yield 'object count above the actual objects' => [static fn () => self::buildPack([self::object(ObjectType::Blob->value, 'a')], 2, 2), ProtocolException::class, 'Pack is truncated'];
        yield 'object larger than the limit' => [static fn () => self::buildPack([self::object(ObjectType::Blob->value, str_repeat('a', 1001))]), LimitExceededException::class, 'exceeds the limit of 1000'];
        yield 'total size above the limit' => [static fn () => self::buildPack([self::object(ObjectType::Blob->value, str_repeat('a', 900)), self::object(ObjectType::Blob->value, str_repeat('b', 900))]), LimitExceededException::class, 'Pack contents exceed the limit of 1500 bytes'];
        yield 'zlib bomb' => [static fn () => self::buildPack([self::object(ObjectType::Blob->value, str_repeat("\0", 5_000_000), declaredSize: 10)]), ProtocolException::class, 'larger than its declared size'];
        yield 'object smaller than declared' => [static fn () => self::buildPack([self::object(ObjectType::Blob->value, 'abc', declaredSize: 10)]), ProtocolException::class, 'smaller than its declared size'];
        yield 'invalid compressed data' => [static fn () => self::buildPack([self::rawObject(ObjectType::Blob->value, 5, 'not zlib data')]), ProtocolException::class, 'Invalid compressed data'];
        yield 'invalid object type' => [static fn () => self::buildPack([self::object(5, 'x')]), ProtocolException::class, 'Invalid object type 5'];
        yield 'trailing data' => [static fn () => self::buildPack([self::object(ObjectType::Blob->value, 'a').'junk']), ProtocolException::class, 'Unexpected data after the last pack object'];
        yield 'offset delta to nowhere' => [static fn () => self::buildPack([self::offsetDelta(5, self::deltaHeader(1, 1)."\x01a")]), ProtocolException::class, 'Delta base offset does not point to an earlier object'];
        yield 'ref delta to a missing base' => [static fn () => self::buildPack([self::refDelta(str_repeat("\1", 20), self::deltaHeader(1, 1)."\x01a")]), ProtocolException::class, 'deltas against objects it does not include'];
        yield 'delta copying outside its base' => [static fn () => self::buildPack([
            self::object(ObjectType::Blob->value, 'base'),
            self::offsetDelta(\strlen(self::object(ObjectType::Blob->value, 'base')), self::deltaHeader(4, 10)."\x90\x0A"),
        ]), ProtocolException::class, 'Delta copies outside of its base'];
        yield 'delta with wrong base size' => [static fn () => self::buildPack([
            self::object(ObjectType::Blob->value, 'base'),
            self::offsetDelta(\strlen(self::object(ObjectType::Blob->value, 'base')), self::deltaHeader(5, 1)."\x01a"),
        ]), ProtocolException::class, 'Delta base size mismatch'];
        yield 'delta result larger than declared' => [static fn () => self::buildPack([
            self::object(ObjectType::Blob->value, 'base'),
            self::offsetDelta(\strlen(self::object(ObjectType::Blob->value, 'base')), self::deltaHeader(4, 1)."\x02ab"),
        ]), ProtocolException::class, 'Delta result is larger than its declared size'];
    }

    /**
     * Walks the pack headers to find out which delta type git used
     */
    private static function hasOffsetDelta(string $pack, ObjectFormat $format): bool
    {
        $count = unpack('N', $pack, 8)[1] ?? 0;
        $pos = 12;
        for ($i = 0; $i < $count; $i++) {
            $byte = \ord($pack[$pos++]);
            $type = ($byte >> 4) & 7;
            $size = $byte & 0x0F;
            $shift = 4;
            while ($byte & 0x80) {
                $byte = \ord($pack[$pos++]);
                $size |= ($byte & 0x7F) << $shift;
                $shift += 7;
            }
            if ($type === 6) {
                return true;
            }
            if ($type === 7) {
                $pos += $format->rawLength();
            }
            $context = inflate_init(\ZLIB_ENCODING_DEFLATE);
            \assert($context !== false);
            inflate_add($context, substr($pack, $pos), \ZLIB_FINISH);
            $pos += inflate_get_read_len($context);
        }

        return false;
    }

    /**
     * @param list<string> $objects encoded objects
     */
    private static function buildPack(array $objects, int $version = 2, ?int $count = null): string
    {
        $pack = 'PACK'.pack('NN', $version, $count ?? \count($objects)).implode('', $objects);

        return $pack.sha1($pack, true);
    }

    private static function object(int $type, string $contents, ?int $declaredSize = null): string
    {
        return self::rawObject($type, $declaredSize ?? \strlen($contents), (string) gzcompress($contents));
    }

    private static function rawObject(int $type, int $size, string $compressed): string
    {
        $byte = ($type << 4) | ($size & 0x0F);
        $size >>= 4;
        $header = '';
        while ($size > 0) {
            $header .= \chr($byte | 0x80);
            $byte = $size & 0x7F;
            $size >>= 7;
        }

        return $header.\chr($byte).$compressed;
    }

    private static function offsetDelta(int $relativeOffset, string $delta): string
    {
        // the offset is relative to the start of this object, encoded big-endian with an offset per extra byte
        $bytes = \chr($relativeOffset & 0x7F);
        while ($relativeOffset >>= 7) {
            $relativeOffset--;
            $bytes = \chr(0x80 | ($relativeOffset & 0x7F)).$bytes;
        }

        return substr(self::rawObject(6, \strlen($delta), ''), 0).$bytes.gzcompress($delta);
    }

    private static function refDelta(string $baseRawOid, string $delta): string
    {
        return self::rawObject(7, \strlen($delta), '').$baseRawOid.gzcompress($delta);
    }

    private static function deltaHeader(int $baseSize, int $resultSize): string
    {
        $encode = static function (int $size): string {
            $out = '';
            do {
                $byte = $size & 0x7F;
                $size >>= 7;
                $out .= \chr($byte | ($size ? 0x80 : 0));
            } while ($size);

            return $out;
        };

        return $encode($baseSize).$encode($resultSize);
    }
}
