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

use App\Package\Git\PktLine;
use App\Package\Git\PktSpecial;
use App\Package\Git\ProtocolException;
use App\Package\Git\ServerRefusedException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

class PktLineTest extends TestCase
{
    public function testEncode(): void
    {
        self::assertSame('000ahello'."\n", PktLine::encode("hello\n"));
        self::assertSame('0004', PktLine::encode(''));
    }

    public function testDecodeRoundTripsPacketsAndSpecials(): void
    {
        $buffer = PktLine::encode("a\n").PktLine::DELIM.PktLine::encode("b\0c").PktLine::RESPONSE_END.PktLine::FLUSH;

        self::assertSame(["a\n", PktSpecial::Delim, "b\0c", PktSpecial::ResponseEnd, PktSpecial::Flush], PktLine::decode($buffer));
    }

    #[DataProvider('provideInvalidBuffers')]
    public function testDecodeRejectsInvalidBuffers(string $buffer, string $message): void
    {
        $this->expectException(ProtocolException::class);
        $this->expectExceptionMessage($message);

        PktLine::decode($buffer);
    }

    /**
     * @return iterable<array{string, string}>
     */
    public static function provideInvalidBuffers(): iterable
    {
        yield 'non hex length' => ['zzzzhello', 'Invalid pkt-line length'];
        yield 'reserved length 3' => ['0003', 'Invalid pkt-line length 3'];
        yield 'truncated length' => ['00', 'Truncated pkt-line length'];
        yield 'truncated payload' => ['0010abc', 'Truncated pkt-line'];
    }

    public function testDemultiplexKeepsBandOneAndSkipsProgress(): void
    {
        $packets = ["\1PA", "\2Counting objects", "\1CK", PktSpecial::Flush];

        self::assertSame('PACK', PktLine::demultiplex($packets));
    }

    public function testDemultiplexThrowsOnServerErrorBand(): void
    {
        $this->expectException(ServerRefusedException::class);
        $this->expectExceptionMessage('filter not allowed');

        PktLine::demultiplex(["\1PA", "\3filter not allowed\n"]);
    }

    public function testDemultiplexRejectsUnknownBands(): void
    {
        $this->expectException(ProtocolException::class);

        PktLine::demultiplex(["\5data"]);
    }

    public function testAssertNotError(): void
    {
        PktLine::assertNotError("version 2\n");
        PktLine::assertNotError(PktSpecial::Flush);

        $this->expectException(ServerRefusedException::class);
        $this->expectExceptionMessage('tree filter allows max depth 0, but got 1');

        PktLine::assertNotError("ERR tree filter allows max depth 0, but got 1\n");
    }
}
