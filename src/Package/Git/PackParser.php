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
 * Reads a pack file (see gitformat-pack(5)) into objects, treating the input as hostile
 */
final readonly class PackParser
{
    // input is inflated in small chunks so a zlib bomb can only overshoot the declared size by ~1000x this
    private const int INFLATE_CHUNK = 16384;
    private const int OBJ_OFS_DELTA = 6;
    private const int OBJ_REF_DELTA = 7;

    public function __construct(
        private ObjectFormat $format,
        private int $maxObjects = 500_000,
        private int $maxObjectSize = 64 * 1024 * 1024,
        private int $maxTotalSize = 512 * 1024 * 1024,
    ) {
    }

    /**
     * @return array<string, GitObject> objects by hex id
     */
    public function parse(string $pack): array
    {
        $hashLength = $this->format->rawLength();
        $length = \strlen($pack);
        if ($length < 12 + $hashLength || !str_starts_with($pack, 'PACK')) {
            throw new ProtocolException('Not a pack file');
        }

        $version = self::readUint32($pack, 4);
        if ($version !== 2 && $version !== 3) {
            throw new ProtocolException('Unsupported pack version '.$version);
        }
        $count = self::readUint32($pack, 8);
        if ($count > $this->maxObjects) {
            throw new LimitExceededException('Pack contains '.$count.' objects, the limit is '.$this->maxObjects);
        }
        $end = $length - $hashLength;
        if (!hash_equals($this->format->hash(substr($pack, 0, $end), true), substr($pack, $end))) {
            throw new ProtocolException('Pack checksum mismatch');
        }

        /** @var array<string, GitObject> $objects */
        $objects = [];
        /** @var array<int, string> $oidsByOffset */
        $oidsByOffset = [];
        /** @var list<array{offset: int, base: string, delta: string}> $pendingRefDeltas */
        $pendingRefDeltas = [];
        $totalSize = 0;
        $pos = 12;

        for ($i = 0; $i < $count; $i++) {
            if ($pos >= $end) {
                throw new ProtocolException('Pack is truncated');
            }
            $offset = $pos;
            $byte = \ord($pack[$pos++]);
            $type = ($byte >> 4) & 7;
            $size = $byte & 0x0F;
            $shift = 4;
            while ($byte & 0x80) {
                if ($pos >= $end || $shift > 56) {
                    throw new ProtocolException('Invalid object header');
                }
                $byte = \ord($pack[$pos++]);
                $size |= ($byte & 0x7F) << $shift;
                $shift += 7;
            }
            if ($size > $this->maxObjectSize) {
                throw new LimitExceededException('Pack object of '.$size.' bytes exceeds the limit of '.$this->maxObjectSize);
            }

            $baseOid = null;
            if ($type === self::OBJ_OFS_DELTA) {
                $byte = \ord($pack[$pos++] ?? "\0");
                $relative = $byte & 0x7F;
                while ($byte & 0x80) {
                    if ($pos >= $end || $relative > \PHP_INT_MAX >> 8) {
                        throw new ProtocolException('Invalid delta offset');
                    }
                    $byte = \ord($pack[$pos++]);
                    $relative = (($relative + 1) << 7) | ($byte & 0x7F);
                }
                $baseOid = $oidsByOffset[$offset - $relative] ?? throw new ProtocolException('Delta base offset does not point to an earlier object');
            } elseif ($type === self::OBJ_REF_DELTA) {
                if ($pos + $hashLength > $end) {
                    throw new ProtocolException('Pack is truncated');
                }
                $baseOid = bin2hex(substr($pack, $pos, $hashLength));
                $pos += $hashLength;
            } elseif (ObjectType::tryFrom($type) === null) {
                throw new ProtocolException('Invalid object type '.$type);
            }

            [$data, $consumed] = $this->inflate($pack, $pos, $end, $size);
            $pos += $consumed;

            if ($baseOid === null) {
                $object = new GitObject(ObjectType::from($type), $data);
            } elseif (isset($objects[$baseOid])) {
                $object = new GitObject($objects[$baseOid]->type, $this->applyDelta($objects[$baseOid]->contents, $data));
            } else {
                // ref deltas may point at objects later in the pack
                $pendingRefDeltas[] = ['offset' => $offset, 'base' => $baseOid, 'delta' => $data];
                continue;
            }

            $totalSize += \strlen($object->contents);
            if ($totalSize > $this->maxTotalSize) {
                throw new LimitExceededException('Pack contents exceed the limit of '.$this->maxTotalSize.' bytes');
            }
            $oid = $this->format->hashObject($object->type, $object->contents);
            $objects[$oid] = $object;
            $oidsByOffset[$offset] = $oid;
        }

        if ($pos !== $end) {
            throw new ProtocolException('Unexpected data after the last pack object');
        }

        while ($pendingRefDeltas !== []) {
            $remaining = [];
            foreach ($pendingRefDeltas as $pending) {
                if (!isset($objects[$pending['base']])) {
                    $remaining[] = $pending;
                    continue;
                }
                $base = $objects[$pending['base']];
                $object = new GitObject($base->type, $this->applyDelta($base->contents, $pending['delta']));
                $totalSize += \strlen($object->contents);
                if ($totalSize > $this->maxTotalSize) {
                    throw new LimitExceededException('Pack contents exceed the limit of '.$this->maxTotalSize.' bytes');
                }
                $objects[$this->format->hashObject($object->type, $object->contents)] = $object;
            }
            if (\count($remaining) === \count($pendingRefDeltas)) {
                throw new ProtocolException('Pack contains deltas against objects it does not include');
            }
            $pendingRefDeltas = $remaining;
        }

        return $objects;
    }

    /**
     * @return array{string, int} inflated data and the number of compressed bytes consumed
     */
    private function inflate(string $pack, int $pos, int $end, int $expectedSize): array
    {
        $context = inflate_init(\ZLIB_ENCODING_DEFLATE);
        if ($context === false) {
            throw new \RuntimeException('Failed to initialize zlib');
        }

        $data = '';
        $cursor = $pos;
        while (true) {
            $chunk = substr($pack, $cursor, min(self::INFLATE_CHUNK, $end - $cursor));
            if ($chunk === '') {
                throw new ProtocolException('Pack is truncated inside a compressed object');
            }
            $output = @inflate_add($context, $chunk, \ZLIB_SYNC_FLUSH);
            if ($output === false) {
                throw new ProtocolException('Invalid compressed data in pack');
            }
            $data .= $output;
            if (\strlen($data) > $expectedSize) {
                throw new ProtocolException('Pack object is larger than its declared size');
            }
            if (inflate_get_status($context) === \ZLIB_STREAM_END) {
                break;
            }
            $cursor += \strlen($chunk);
        }

        if (\strlen($data) !== $expectedSize) {
            throw new ProtocolException('Pack object is smaller than its declared size');
        }

        // read length counts the bytes zlib consumed up to the end of the stream, across all chunks
        return [$data, inflate_get_read_len($context)];
    }

    private function applyDelta(string $base, string $delta): string
    {
        $pos = 0;
        $deltaLength = \strlen($delta);
        $baseSize = $this->readDeltaSize($delta, $pos);
        $resultSize = $this->readDeltaSize($delta, $pos);
        if ($baseSize !== \strlen($base)) {
            throw new ProtocolException('Delta base size mismatch');
        }
        if ($resultSize > $this->maxObjectSize) {
            throw new LimitExceededException('Delta result of '.$resultSize.' bytes exceeds the limit of '.$this->maxObjectSize);
        }

        $result = '';
        while ($pos < $deltaLength) {
            $opcode = \ord($delta[$pos++]);
            if ($opcode & 0x80) {
                $copyOffset = $copySize = 0;
                for ($i = 0; $i < 4; $i++) {
                    if ($opcode & (1 << $i)) {
                        $copyOffset |= \ord($delta[$pos++] ?? throw new ProtocolException('Truncated delta')) << ($i * 8);
                    }
                }
                for ($i = 0; $i < 3; $i++) {
                    if ($opcode & (1 << (4 + $i))) {
                        $copySize |= \ord($delta[$pos++] ?? throw new ProtocolException('Truncated delta')) << ($i * 8);
                    }
                }
                if ($copySize === 0) {
                    $copySize = 0x10000;
                }
                if ($copyOffset + $copySize > $baseSize) {
                    throw new ProtocolException('Delta copies outside of its base');
                }
                $result .= substr($base, $copyOffset, $copySize);
            } elseif ($opcode > 0) {
                if ($pos + $opcode > $deltaLength) {
                    throw new ProtocolException('Truncated delta');
                }
                $result .= substr($delta, $pos, $opcode);
                $pos += $opcode;
            } else {
                throw new ProtocolException('Invalid delta opcode 0');
            }
            if (\strlen($result) > $resultSize) {
                throw new ProtocolException('Delta result is larger than its declared size');
            }
        }

        if (\strlen($result) !== $resultSize) {
            throw new ProtocolException('Delta result size mismatch');
        }

        return $result;
    }

    private static function readUint32(string $data, int $offset): int
    {
        $value = unpack('N', $data, $offset);
        if ($value === false || !\is_int($value[1] ?? null)) {
            throw new ProtocolException('Truncated pack header');
        }

        return $value[1];
    }

    private function readDeltaSize(string $delta, int &$pos): int
    {
        $size = 0;
        $shift = 0;
        do {
            if (!isset($delta[$pos]) || $shift > 56) {
                throw new ProtocolException('Invalid delta header');
            }
            $byte = \ord($delta[$pos++]);
            $size |= ($byte & 0x7F) << $shift;
            $shift += 7;
        } while ($byte & 0x80);

        return $size;
    }
}
