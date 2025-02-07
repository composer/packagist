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

namespace App\SecurityAdvisory;

use App\Entity\SecurityAdvisory;
use App\Entity\User;
use App\Model\ProviderManager;
use Composer\IO\ConsoleIO;
use Composer\Pcre\Preg;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\LoggerInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

/**
 * @author Yanick Witschi <yanick.witschi@terminal42.ch>
 */
class GitHubSecurityAdvisoriesSource implements SecurityAdvisorySourceInterface
{
    public const SOURCE_NAME = 'GitHub';

    private const IGNORE_CVES = [
        'CVE-2024-36611', // @see https://phpc.social/@wouterj/113588554019692959
        'CVE-2024-36610', // @see https://phpc.social/@wouterj/113588554019692959
        'CVE-2024-57610', // store.sylius.com issue incorrectly filed as general sylius vulnerability https://github.com/nca785/CVE-2024-57610
    ];

    /**
     * @param list<string> $fallbackGhTokens
     */
    public function __construct(
        private HttpClientInterface $httpClient,
        private LoggerInterface $logger,
        private ProviderManager $providerManager,
        private array $fallbackGhTokens,
        private ManagerRegistry $doctrine,
    ) {
    }

    public function getAdvisories(ConsoleIO $io): ?RemoteSecurityAdvisoryCollection
    {
        /** @var array<string, array<string, RemoteSecurityAdvisory>> $advisoryMap */
        $advisoryMap = [];
        /** @var array<string, array<string, true>> $foundPackageCves */
        $foundPackageCves = [];
        $hasNextPage = true;
        $after = '';

        while ($hasNextPage) {
            $headers = [];
            if (count($this->fallbackGhTokens) > 0) {
                $fallbackUser = $this->doctrine->getRepository(User::class)->findOneBy(['usernameCanonical' => $this->fallbackGhTokens[random_int(0, count($this->fallbackGhTokens) - 1)]]);
                if (null !== $fallbackUser?->getGithubToken()) {
                    $headers = ['Authorization' => 'token ' . $fallbackUser->getGithubToken()];
                }
            }

            try {
                $response = $this->httpClient->request('POST', 'https://api.github.com/graphql', [
                    'headers' => $headers,
                    'json' => ['query' => $this->getQuery($after)],
                ]);
            } catch (\RuntimeException $e) {
                $this->logger->error('Failed to fetch GitHub advisories', [
                    'exception' => $e,
                ]);

                return null;
            }

            $data = json_decode($response->getContent(), true, JSON_THROW_ON_ERROR);
            $data = $data['data'];

            foreach ($data['securityVulnerabilities']['nodes'] as $node) {
                $remoteId = null;
                $cve = null;

                if (isset($node['advisory']['withdrawnAt'])) {
                    continue;
                }

                foreach ($node['advisory']['identifiers'] as $identifier) {
                    if ('GHSA' === $identifier['type']) {
                        $remoteId = $identifier['value'];
                        continue;
                    }
                    if ('CVE' === $identifier['type']) {
                        $cve = $identifier['value'];
                    }
                }
                if (null === $remoteId) {
                    continue;
                }

                if (in_array($cve, self::IGNORE_CVES, true)) {
                    continue;
                }

                $packageName = strtolower($node['package']['name']);

                // GitHub adds spaces everywhere e.g. > 1.0, adjust to be able to match other advisories
                $versionRange = Preg::replace('#\s#', '', $node['vulnerableVersionRange']);
                if (isset($advisoryMap[$packageName][$remoteId])) {
                    $advisoryMap[$packageName][$remoteId] = $advisoryMap[$packageName][$remoteId]->withAddedAffectedVersion($versionRange);
                    continue;
                }

                // GitHub can have multiple advisories per CVE and package
                if ($cve !== null) {
                    if (isset($foundPackageCves[$packageName][$cve])) {
                        continue;
                    }

                    $foundPackageCves[$packageName][$cve] = true;
                }

                $references = [];
                foreach ($node['advisory']['references'] as $reference) {
                    if (isset($reference['url'])) {
                        $references[] = $reference['url'];
                    }
                }

                $date = \DateTimeImmutable::createFromFormat(\DateTimeInterface::ISO8601, $node['advisory']['publishedAt']);
                if ($date === false) {
                    throw new \InvalidArgumentException('Invalid date format returned from GitHub');
                }

                $advisoryMap[$packageName][$remoteId] = new RemoteSecurityAdvisory(
                    $remoteId,
                    $node['advisory']['summary'],
                    $packageName,
                    $versionRange,
                    $node['advisory']['permalink'],
                    $cve,
                    $date,
                    $this->providerManager->packageExists($packageName) ? SecurityAdvisory::PACKAGIST_ORG : null,
                    $references,
                    self::SOURCE_NAME,
                    Severity::fromGitHub($node['advisory']['severity']),
                );
            }

            $hasNextPage = $data['securityVulnerabilities']['pageInfo']['hasNextPage'];
            $after = $data['securityVulnerabilities']['pageInfo']['endCursor'];
        }

        $advisories = [];
        foreach ($advisoryMap as $packageAdvisories) {
            foreach ($packageAdvisories as $packageAdvisory) {
                $advisories[] = $packageAdvisory;
            }
        }

        return new RemoteSecurityAdvisoryCollection($advisories);
    }

    private function getQuery(string $after = ''): string
    {
        if ('' !== $after) {
            $after = sprintf(',after:"%s"', $after);
        }

        $query = <<<QUERY
query {
  securityVulnerabilities(ecosystem:COMPOSER,first:100%s) {
    nodes {
      advisory {
        summary,
        permalink,
        publishedAt,
        withdrawnAt,
        severity,
        identifiers {
          type,
          value
        },
        references {
          url
        }
      },
      vulnerableVersionRange,
      package {
        name
      }
    },
    pageInfo {
      hasNextPage,
      endCursor
    }
  }
}
QUERY;

        return Preg::replace('/[ \t\n]+/', '', sprintf($query, $after));
    }
}
