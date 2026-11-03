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

namespace App\Package\Vcs;

use App\Package\Git\RemoteRepository;
use App\Package\Git\RepositoryNotFoundException;
use App\Package\Git\SmartHttpClient;
use App\Package\Git\UnsupportedRepositoryException;
use App\Util\HttpDownloaderOptionsFactory;
use Composer\Config;
use Composer\IO\IOInterface;
use Composer\Pcre\Preg;
use Composer\Repository\Vcs\GitDriver;
use Composer\Util\HttpDownloader;

/**
 * Reads git repositories over smart HTTP in PHP, without the git binary
 *
 * Extends GitDriver because the API drivers type their fallback driver as such.
 *
 * Set the "known-references" repo config to the commit ids the caller already has data for, so the commits it
 * is going to ask about are fetched in a few batched requests instead of one by one.
 */
class HttpGitDriver extends GitDriver
{
    /** root-level files read for every version, which are prefetched along with the commits */
    private const array PREFETCHED_FILES = ['composer.json'];

    private RemoteRepository $remote;
    private bool $prefetched = false;

    public function initialize(): void
    {
        $httpsUrl = self::toHttpsUrl($this->url) ?? throw new UnsupportedRepositoryException('Only https:// git repository URLs are supported, got '.$this->url);

        $this->remote = new RemoteRepository(new SmartHttpClient($this->httpDownloader, $httpsUrl));
        $refs = $this->remote->getRefs();
        $this->tags = $refs->tags;
        $this->branches = $refs->branches;
    }

    public function getRootIdentifier(): string
    {
        if (null === $this->rootIdentifier) {
            $refs = $this->remote->getRefs();
            // a HEAD pointing to a missing branch falls back like GitDriver does
            $this->rootIdentifier = $refs->defaultBranch ?? (isset($refs->branches['master']) || $refs->branches === [] ? 'master' : (string) array_key_first($refs->branches));
        }

        return $this->rootIdentifier;
    }

    public function getFileContent(string $file, string $identifier): ?string
    {
        $commit = $this->resolveCommit($identifier);
        if ($commit === null) {
            return null;
        }

        $this->prefetch();
        $content = $this->remote->readFile($commit, $file);

        return $content === null || trim($content) === '' ? null : $content;
    }

    public function getChangeDate(string $identifier): ?\DateTimeImmutable
    {
        $commit = $this->resolveCommit($identifier);
        if ($commit === null) {
            return null;
        }

        $this->prefetch();
        $authorTime = $this->remote->getCommitInfo($commit)['authorTime'] ?? null;

        return $authorTime === null ? null : new \DateTimeImmutable('@'.$authorTime, new \DateTimeZone('UTC'));
    }

    public function getTags(): array
    {
        return $this->tags;
    }

    public function getBranches(): array
    {
        return $this->branches;
    }

    public static function supports(IOInterface $io, Config $config, string $url, bool $deep = false): bool
    {
        $httpsUrl = self::toHttpsUrl($url);
        if ($httpsUrl === null) {
            return false;
        }

        if (Preg::isMatch('#(\.git/?$|git(?:olite)?@|//git\.|//github.com/)#i', $url)) {
            return true;
        }

        if (!$deep) {
            return false;
        }

        try {
            new SmartHttpClient(new HttpDownloader($io, $config, HttpDownloaderOptionsFactory::getOptions()), $httpsUrl)->getObjectFormat();
        } catch (RepositoryNotFoundException) {
            return false;
        }

        return true;
    }

    /**
     * Turns the SSH URLs Composer's API drivers fall back to into their https equivalent
     */
    public static function toHttpsUrl(string $url): ?string
    {
        if (str_starts_with($url, 'https://')) {
            return $url;
        }
        if (Preg::isMatchStrictGroups('{^(?:ssh://)?[a-z0-9_.-]+@([a-z0-9.-]+)(?::\d+)?[:/](.+)$}i', $url, $match)) {
            return 'https://'.$match[1].'/'.ltrim($match[2], '/');
        }

        return null;
    }

    private function resolveCommit(string $identifier): ?string
    {
        if (str_starts_with($identifier, '-')) {
            throw new \RuntimeException('Invalid git identifier detected. Identifier must not start with a -, given: '.$identifier);
        }

        if ($this->remote->getObjectFormat()->isObjectId($identifier)) {
            return $identifier;
        }

        return $this->branches[$identifier] ?? $this->tags[$identifier] ?? null;
    }

    /**
     * Fetches every ref tip the caller has no data for yet, as it is going to ask about all of them
     */
    private function prefetch(): void
    {
        if ($this->prefetched || !isset($this->repoConfig['known-references']) || !\is_array($this->repoConfig['known-references'])) {
            return;
        }
        $this->prefetched = true;

        $known = array_flip(array_filter($this->repoConfig['known-references'], \is_string(...)));
        $commits = [];
        foreach ([...array_values($this->branches), ...array_values($this->tags)] as $commit) {
            if (!isset($known[$commit])) {
                $commits[$commit] = true;
            }
        }
        $root = $this->branches[$this->getRootIdentifier()] ?? null;
        if ($root !== null) {
            $commits[$root] = true;
        }

        if ($commits !== []) {
            $this->remote->prefetch(array_keys($commits), self::PREFETCHED_FILES);
        }
    }
}
