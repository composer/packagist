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
 * Reads files at given commits of a remote repository, fetching as few objects as the server allows
 */
final class RemoteRepository
{
    private const int MAX_WANTS_PER_REQUEST = 200;
    private const int MAX_SNAPSHOTS_PER_REQUEST = 10;

    private ?RemoteRefs $refs = null;
    private ?FetchMode $mode = null;
    /** @var array<string, GitObject> */
    private array $objects = [];
    /** @var array<string, true> commits whose full snapshot was fetched */
    private array $snapshots = [];
    private int $storedBytes = 0;

    public function __construct(
        private readonly SmartHttpClient $client,
        private readonly int $maxStoredBytes = 256 * 1024 * 1024,
    ) {
    }

    public function getRefs(): RemoteRefs
    {
        return $this->refs ??= $this->client->listRefs();
    }

    public function getObjectFormat(): ObjectFormat
    {
        return $this->client->getObjectFormat();
    }

    public function getMode(): FetchMode
    {
        if ($this->mode === null) {
            if (!$this->client->supportsShallow()) {
                throw new UnsupportedRepositoryException('The server does not support shallow fetches');
            }
            $this->mode = $this->client->supportsFilter() && $this->client->allowsReachableWants() ? FetchMode::TreeDepth1 : FetchMode::Snapshot;
        }

        return $this->mode;
    }

    /**
     * Fetches the given commits and the given root-level files in as few requests as possible
     *
     * @param list<string> $commits
     * @param list<string> $files
     */
    public function prefetch(array $commits, array $files): void
    {
        $this->fetchCommits($commits);

        if ($this->getMode() === FetchMode::Snapshot) {
            return;
        }

        $blobs = [];
        foreach ($commits as $commit) {
            $info = $this->getCommitInfo($commit);
            $tree = $info === null ? null : $this->objects[$info['tree']] ?? null;
            if ($tree === null) {
                continue;
            }
            $entries = $tree->parseTree($this->getObjectFormat());
            foreach ($files as $file) {
                if (isset($entries[$file]) && str_starts_with($entries[$file]['mode'], '100') && !isset($this->objects[$entries[$file]['oid']])) {
                    $blobs[$entries[$file]['oid']] = true;
                }
            }
        }

        try {
            $this->fetchByIds(array_keys($blobs), null);
        } catch (ServerRefusedException) {
            // readFile() falls back to snapshots for these
            $this->mode = FetchMode::Snapshot;
        }
    }

    /**
     * @return ?array{tree: string, authorTime: ?int} null if a ref points to something else than a commit, e.g. a tag of a blob
     */
    public function getCommitInfo(string $commit): ?array
    {
        $this->fetchCommits([$commit]);

        $object = $this->getStoredObject($commit);

        return $object->type === ObjectType::Commit ? $object->parseCommit($this->getObjectFormat()) : null;
    }

    /**
     * @return ?string the file contents, or null if the path does not exist or is not a file
     */
    public function readFile(string $commit, string $path): ?string
    {
        try {
            return $this->doReadFile($commit, $path);
        } catch (ServerRefusedException $e) {
            if ($this->getMode() === FetchMode::Snapshot) {
                throw $e;
            }
            // the server would not hand out a tree or blob by id, a full snapshot of the commit has everything
            $this->mode = FetchMode::Snapshot;

            return $this->doReadFile($commit, $path);
        }
    }

    private function doReadFile(string $commit, string $path): ?string
    {
        $format = $this->getObjectFormat();
        if ($this->getMode() === FetchMode::Snapshot && !isset($this->snapshots[$commit])) {
            $this->fetchSnapshots([$commit]);
        }
        $info = $this->getCommitInfo($commit);
        if ($info === null) {
            return null;
        }
        $oid = $info['tree'];
        $segments = explode('/', trim($path, '/'));
        $last = \count($segments) - 1;

        foreach ($segments as $index => $segment) {
            $entries = $this->getObject($oid, ObjectType::Tree)->parseTree($format);
            if (!isset($entries[$segment])) {
                return null;
            }
            $entry = $entries[$segment];
            $isTree = $entry['mode'] === '40000';
            if ($index < $last ? !$isTree : !str_starts_with($entry['mode'], '100')) {
                return null;
            }
            $oid = $entry['oid'];
        }

        return $this->getObject($oid, ObjectType::Blob)->contents;
    }

    private function getObject(string $oid, ObjectType $type): GitObject
    {
        if (!isset($this->objects[$oid])) {
            if ($this->getMode() === FetchMode::Snapshot) {
                throw new ProtocolException('The server snapshot is missing object '.$oid);
            }
            $this->fetchByIds([$oid], $type === ObjectType::Tree ? 'tree:0' : null);
        }

        $object = $this->objects[$oid] ?? throw new ProtocolException('The server did not send object '.$oid);
        if ($object->type !== $type) {
            throw new ProtocolException('Expected '.$oid.' to be a '.$type->label().', got a '.$object->type->label());
        }

        return $object;
    }

    /**
     * @param list<string> $commits
     */
    private function fetchCommits(array $commits): void
    {
        $missing = array_values(array_unique(array_filter($commits, fn ($commit) => !isset($this->objects[$commit]))));
        if ($missing === []) {
            return;
        }

        $mode = $this->getMode();
        if ($mode === FetchMode::TreeDepth1) {
            try {
                foreach (array_chunk($missing, self::MAX_WANTS_PER_REQUEST) as $chunk) {
                    $this->store($this->client->fetch($chunk, true, 'tree:1'));
                }
            } catch (ServerRefusedException) {
                // GitHub only allows tree:0
                $this->mode = $mode = FetchMode::TreeDepth0;
            }
        }

        if ($mode === FetchMode::TreeDepth0) {
            try {
                foreach (array_chunk($missing, self::MAX_WANTS_PER_REQUEST) as $chunk) {
                    $this->store($this->client->fetch($chunk, true, 'tree:0'));
                }
                // tags of trees are filtered out of a shallow tree:0 fetch even though they were wanted
                $this->fetchByIds(array_values(array_filter($missing, fn ($oid) => !isset($this->objects[$oid]))), 'tree:0');
                $trees = [];
                foreach ($missing as $commit) {
                    $object = $this->getStoredObject($commit);
                    if ($object->type !== ObjectType::Commit) {
                        continue;
                    }
                    $tree = $object->parseCommit($this->getObjectFormat())['tree'];
                    if (!isset($this->objects[$tree])) {
                        $trees[$tree] = true;
                    }
                }
                $this->fetchByIds(array_keys($trees), 'tree:0');
            } catch (ServerRefusedException) {
                $this->mode = $mode = FetchMode::Snapshot;
            }
        }

        if ($mode === FetchMode::Snapshot) {
            $this->fetchSnapshots($missing);
        }

        foreach ($missing as $commit) {
            $this->getStoredObject($commit);
        }
    }

    /**
     * @param list<string> $commits
     */
    private function fetchSnapshots(array $commits): void
    {
        $refs = $this->getRefs();
        $wants = [];
        foreach ($commits as $commit) {
            // v0 servers only accept advertised ids, which is the tag object for annotated tags
            $wants[] = $this->client->allowsReachableWants() ? $commit : ($refs->advertisedTips[$commit] ?? throw new ProtocolException('Commit '.$commit.' is not a ref tip and the server only allows fetching tips'));
        }

        foreach (array_chunk(array_values(array_unique($wants)), self::MAX_SNAPSHOTS_PER_REQUEST) as $chunk) {
            $this->fetchSnapshotChunk($chunk);
        }
        foreach ($commits as $commit) {
            $this->snapshots[$commit] = true;
        }
    }

    /**
     * @param list<string> $wants
     */
    private function fetchSnapshotChunk(array $wants): void
    {
        if ($wants === []) {
            return;
        }

        try {
            $this->store($this->client->fetch($wants, true, null));
        } catch (LimitExceededException $e) {
            // several snapshots of a large repository may not fit in one response when each one alone does
            if (\count($wants) === 1) {
                throw $e;
            }
            $half = intdiv(\count($wants), 2);
            $this->fetchSnapshotChunk(\array_slice($wants, 0, $half));
            $this->fetchSnapshotChunk(\array_slice($wants, $half));
        }
    }

    /**
     * @param list<string> $oids
     */
    private function fetchByIds(array $oids, ?string $filter): void
    {
        foreach (array_chunk($oids, self::MAX_WANTS_PER_REQUEST) as $chunk) {
            $this->store($this->client->fetch($chunk, false, $filter));
        }
    }

    private function getStoredObject(string $oid): GitObject
    {
        return $this->objects[$oid] ?? throw new ProtocolException('The server did not send '.$oid);
    }

    private function store(string $pack): void
    {
        foreach (new PackParser($this->getObjectFormat())->parse($pack) as $oid => $object) {
            if (isset($this->objects[$oid])) {
                continue;
            }
            $this->storedBytes += \strlen($object->contents);
            if ($this->storedBytes > $this->maxStoredBytes) {
                throw new LimitExceededException('The repository data exceeds the limit of '.$this->maxStoredBytes.' bytes');
            }
            $this->objects[$oid] = $object;
        }
    }
}
