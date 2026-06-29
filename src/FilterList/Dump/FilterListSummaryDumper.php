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

namespace App\FilterList\Dump;

use App\Entity\FilterListEntryRepository;
use App\Service\CdnClient;
use Psr\Log\LoggerInterface;

readonly class FilterListSummaryDumper
{
    public const string SUMMARY_PATH = 'lists/all/summary.json';

    public function __construct(
        private FilterListDumperProvider $provider,
        private FilterListEntryRepository $repository,
        private CdnClient $cdn,
        private LoggerInterface $logger,
    ) {
    }

    /**
     * Dump the summary file when needed and purge the CDN cache afterwards.
     *
     * @param bool $forceDump Skip the staleness check (use when entries were added/removed in the current run).
     */
    public function dumpIfStale(bool $forceDump): void
    {
        if (!$forceDump && !$this->isStale()) {
            return;
        }

        $this->dump();
    }

    private function isStale(): bool
    {
        $newestCreatedAt = $this->repository->getNewestEntryUpdatedAt();
        if ($newestCreatedAt === null) {
            return false;
        }

        return !$this->cdn->wasPublicRepoFileModifiedSince(self::SUMMARY_PATH, $newestCreatedAt);
    }

    private function dump(): void
    {
        $summary = $this->provider->getSummary();
        $json = json_encode(
            $summary->toJsonPayload(),
            \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE | \JSON_THROW_ON_ERROR,
        );

        try {
            $this->cdn->uploadMetadata(self::SUMMARY_PATH, $json);
        } catch (\Throwable $e) {
            $this->logger->error('Failed to upload filter list summary', ['exception' => $e]);
            throw $e;
        }

        $this->cdn->purgeSummaryUrl();
    }
}
