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

namespace App\Package;

use Predis\Client;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Holds the finished /packages/list.json body, gzipped, so serving it costs one GET rather than
 * reading and sorting all package names out of Redis on every CDN miss. The endpoint is cached
 * per CDN edge, so the origin sees it far more often than its s-maxage suggests.
 */
class PackageListCache
{
    private const string BLOB = 'str:packages:list.gz';
    private const string VERSION = 'str:packages:list:version';
    private const string BUILT = 'str:packages:list:built';

    /** Level 9 saves ~4KB over level 6 on the real list, for 2.6x the CPU. */
    private const int GZIP_LEVEL = 6;

    /** Must match PackageController's streamed list responses, as this blob replaces that output. */
    private const int ENCODING_OPTIONS = JsonResponse::DEFAULT_ENCODING_OPTIONS | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE;

    public function __construct(private Client $redis)
    {
    }

    /**
     * The gzipped response body, or null if it has not been built yet.
     */
    public function read(): ?string
    {
        $blob = $this->redis->get(self::BLOB);

        return \is_string($blob) && $blob !== '' ? $blob : null;
    }

    /**
     * Whether a blob has been built at all. Distinct from the version comparison, which cannot tell
     * a cold cache (both counters at 0) apart from an up-to-date one.
     */
    public function exists(): bool
    {
        return (bool) $this->redis->exists(self::BLOB);
    }

    /**
     * Drops the blob so the next request falls back to the live listing until the dump runs again.
     */
    public function clear(): void
    {
        $this->redis->del([self::BLOB, self::VERSION, self::BUILT]);
    }

    /**
     * Monotonic on purpose: a change landing mid-build leaves this ahead of the built version, so
     * the next run rebuilds instead of the change being lost.
     */
    public function markStale(): void
    {
        $this->redis->incr(self::VERSION);
    }

    public function getVersion(): int
    {
        return (int) $this->redis->get(self::VERSION);
    }

    public function getBuiltVersion(): int
    {
        return (int) $this->redis->get(self::BUILT);
    }

    /**
     * @param string[] $names sorted, as the endpoint's output order is part of its contract
     */
    public function write(array $names, int $builtVersion): void
    {
        $blob = gzencode(json_encode(['packageNames' => $names], self::ENCODING_OPTIONS | JSON_THROW_ON_ERROR), self::GZIP_LEVEL);
        if ($blob === false) {
            throw new \RuntimeException('Failed to gzip the package list');
        }

        $this->redis->mset([self::BLOB => $blob, self::BUILT => (string) $builtVersion]);
    }
}
