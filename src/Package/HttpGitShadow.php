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

use App\Entity\Package;
use App\Package\Vcs\HttpGitDriver;
use App\Service\VersionCache;
use Composer\Config;
use Composer\Downloader\TransportException;
use Composer\IO\NullIO;
use Composer\Repository\Vcs\GitDriver;
use Composer\Repository\Vcs\VcsDriverInterface;
use Composer\Util\HttpDownloader;
use Composer\Util\ProcessExecutor;
use Graze\DogStatsD\Client as StatsDClient;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;

/**
 * Reads repositories that were updated with the git binary again with HttpGitDriver and reports where the two differ
 */
class HttpGitShadow
{
    private const int MAX_REPORTED_DIFFERENCES = 20;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly StatsDClient $statsd,
        private readonly CacheItemPoolInterface $cache,
        private readonly int $samplePercent = 10,
    ) {
    }

    public function compareWithUpdate(Package $package, VcsDriverInterface $driver, ?VersionCache $versionCache, Config $config, HttpDownloader $httpDownloader): void
    {
        if (random_int(1, 100) > $this->samplePercent) {
            return;
        }

        $gitDriver = self::findGitDriver($driver);
        if ($gitDriver === null || null === ($url = HttpGitDriver::toHttpsUrl($gitDriver->getUrl()))) {
            return;
        }

        $failure = null;
        try {
            $knownReferences = $versionCache?->getKnownReferences() ?? [];
            $io = new NullIO();
            $shadow = new HttpGitDriver(['url' => $url, 'known-references' => $knownReferences], $io, $config, $httpDownloader, new ProcessExecutor($io));
            $differences = self::diff($gitDriver, $shadow, $knownReferences);
        } catch (\Throwable $failure) {
            // the message is left out as it can vary between crawls (timings, request ids), which would defeat the dedup
            $status = $failure instanceof TransportException ? $failure->getStatusCode() : null;
            $differences = ['HttpGitDriver failed: '.$failure::class.($status !== null ? ' (HTTP '.$status.')' : '')];
        }

        try {
            $this->report($package, $url, $differences, $failure);
        } catch (\Throwable $e) {
            $this->logger->error('Could not report the HttpGitDriver shadow result', ['package' => $package->getName(), 'exception' => $e]);
        }
    }

    /**
     * @param list<string> $knownReferences commits which were not read by the update, so are not compared
     *
     * @return list<string>
     */
    public static function diff(GitDriver $reference, HttpGitDriver $shadow, array $knownReferences): array
    {
        $shadow->initialize();

        $differences = [];
        if ($reference->getRootIdentifier() !== $shadow->getRootIdentifier()) {
            $differences[] = 'default branch: '.$reference->getRootIdentifier().' vs '.$shadow->getRootIdentifier();
        }

        $known = array_flip($knownReferences);
        $compared = [];
        foreach (['tag' => [$reference->getTags(), $shadow->getTags()], 'branch' => [$reference->getBranches(), $shadow->getBranches()]] as $type => [$expected, $actual]) {
            foreach ($expected + $actual as $name => $unused) {
                $commit = $expected[$name] ?? null;
                if ($commit !== ($actual[$name] ?? null)) {
                    $differences[] = $type.' '.$name.': '.($commit ?? 'missing').' vs '.($actual[$name] ?? 'missing');
                    continue;
                }

                if ($commit === null || isset($known[$commit]) || isset($compared[$commit])) {
                    continue;
                }
                $compared[$commit] = true;

                if (self::readComposerInformation($reference, $commit) !== self::readComposerInformation($shadow, $commit)) {
                    $differences[] = 'composer.json of '.$type.' '.$name.' ('.$commit.')';
                }
            }
        }

        return $differences;
    }

    private static function findGitDriver(VcsDriverInterface $driver): ?GitDriver
    {
        if ($driver instanceof GitDriver) {
            return $driver instanceof HttpGitDriver ? null : $driver;
        }

        // API drivers fall back to a GitDriver in these properties
        foreach (['gitDriver', 'fallbackDriver'] as $property) {
            if (property_exists($driver, $property)) {
                $fallback = new \ReflectionProperty($driver, $property)->getValue($driver);
                if ($fallback instanceof GitDriver && !$fallback instanceof HttpGitDriver) {
                    return $fallback;
                }
            }
        }

        return null;
    }

    /**
     * @return array<mixed>|string|null
     */
    private static function readComposerInformation(GitDriver $driver, string $identifier): array|string|null
    {
        try {
            return $driver->getComposerInformation($identifier);
        } catch (\Throwable $e) {
            return $e::class;
        }
    }

    /**
     * @param list<string> $differences
     */
    private function report(Package $package, string $url, array $differences, ?\Throwable $failure): void
    {
        $result = match (true) {
            $failure !== null => 'failure',
            $differences === [] => 'match',
            default => 'mismatch',
        };
        $this->statsd->increment('packagist.update.http_git_shadow', 1, 1, ['result' => $result]);

        // log once per distinct result as the same mismatch comes back on every crawl
        $item = $this->cache->getItem('http_git_shadow_'.$package->getId());
        if ($differences === []) {
            if ($item->isHit()) {
                $this->cache->deleteItem($item->getKey());
                $this->logger->info('HttpGitDriver matches the git binary again', ['package' => $package->getName(), 'url' => $url]);
            }

            return;
        }

        $hash = hash('sha256', implode("\n", $differences));
        if ($item->isHit() && $item->get() === $hash) {
            return;
        }
        $this->cache->save($item->set($hash)->expiresAfter(30 * 86400));

        $this->logger->error('HttpGitDriver differs from the git binary', [
            'package' => $package->getName(),
            'url' => $url,
            'differences' => \array_slice($differences, 0, self::MAX_REPORTED_DIFFERENCES),
            'differenceCount' => \count($differences),
            'exception' => $failure,
        ]);
    }
}
