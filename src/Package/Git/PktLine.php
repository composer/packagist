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

namespace App\Package\Git;

/**
 * pkt-line framing, see gitprotocol-common(5)
 */
final class PktLine
{
    public const string FLUSH = '0000';
    public const string DELIM = '0001';
    public const string RESPONSE_END = '0002';

    private const int MAX_LENGTH = 65520;

    public static function encode(string $payload): string
    {
        if (\strlen($payload) + 4 > self::MAX_LENGTH) {
            throw new \LogicException('pkt-line payload too long');
        }

        return \sprintf('%04x', \strlen($payload) + 4).$payload;
    }

    /**
     * Splits a buffer into pkt-lines, with flush/delim/response-end packets as PktSpecial cases
     *
     * @return list<string|PktSpecial>
     */
    public static function decode(string $buffer): array
    {
        $packets = [];
        $pos = 0;
        $length = \strlen($buffer);
        while ($pos < $length) {
            if ($pos + 4 > $length) {
                throw new ProtocolException('Truncated pkt-line length');
            }
            $hex = substr($buffer, $pos, 4);
            if (!ctype_xdigit($hex)) {
                throw new ProtocolException('Invalid pkt-line length "'.addcslashes($hex, "\0..\37\177..\377").'"');
            }
            $packetLength = (int) hexdec($hex);
            if ($packetLength < 4) {
                $packets[] = match ($packetLength) {
                    0 => PktSpecial::Flush,
                    1 => PktSpecial::Delim,
                    2 => PktSpecial::ResponseEnd,
                    default => throw new ProtocolException('Invalid pkt-line length '.$packetLength),
                };
                $pos += 4;
                continue;
            }
            if ($pos + $packetLength > $length) {
                throw new ProtocolException('Truncated pkt-line');
            }
            $packets[] = substr($buffer, $pos + 4, $packetLength - 4);
            $pos += $packetLength;
        }

        return $packets;
    }

    /**
     * Reassembles side-band data (band 1) from packets, failing on server errors (band 3) which servers also use to refuse requests
     *
     * @param list<string|PktSpecial> $packets
     */
    public static function demultiplex(array $packets): string
    {
        $data = '';
        foreach ($packets as $packet) {
            if ($packet instanceof PktSpecial) {
                continue;
            }
            $band = $packet[0] ?? '';
            if ($band === "\1") {
                $data .= substr($packet, 1);
            } elseif ($band === "\3") {
                throw new ServerRefusedException(trim(substr($packet, 1)));
            } elseif ($band !== "\2") {
                throw new ProtocolException('Unexpected side-band '.\ord($band));
            }
        }

        return $data;
    }

    /**
     * Throws if the packet is an "ERR" packet, which servers send instead of a response when they refuse a request
     */
    public static function assertNotError(string|PktSpecial $packet): void
    {
        if (\is_string($packet) && str_starts_with($packet, 'ERR ')) {
            throw new ServerRefusedException(trim(substr($packet, 4)));
        }
    }
}
