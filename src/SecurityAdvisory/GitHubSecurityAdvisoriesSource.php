<?php


namespace App\SecurityAdvisory;

use App\Entity\Package;
use Composer\IO\ConsoleIO;
use GuzzleHttp\Client;

/**
 * @author Yanick Witschi <yanick.witschi@terminal42.ch>
 */
class GitHubSecurityAdvisoriesSource implements SecurityAdvisorySourceInterface
{
    public const SOURCE_NAME = 'GitHub';

    private Client $guzzle;

    public function __construct(Client $guzzle)
    {
        $this->guzzle = $guzzle;
    }

    public function getAdvisories(ConsoleIO $io, Package $package): ?array
    {
        $githubToken = null;
        $maintainers = $package->getMaintainers();
        foreach ($maintainers as $maintainer) {
            if ($maintainer->getGithubToken()) {
                $githubToken = $maintainer->getGithubToken();
                break;
            }
        }

        if (null === $githubToken) {
            return [];
        }

        /** @var RemoteSecurityAdvisory[] $advisories */
        $advisories = [];
        $hasNextPage = true;
        $after = '';

        while ($hasNextPage) {
            $opts = [
                'headers' => ['Authorization' => 'Bearer ' . $githubToken],
                'json' => ['query' => $this->getQuery($package->getName(), $after)],
            ];

            $response = $this->guzzle->request('POST', 'https://api.github.com/graphql', $opts);
            $data = json_decode($response->getBody()->getContents(), true);
            $data = $data['data'];

            foreach ($data['securityVulnerabilities']['nodes'] as $node) {
                $remoteId = null;
                $cve = null;

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

                if (isset($advisories[$remoteId])) {
                    $advisories[$remoteId] = $advisories[$remoteId]->withAddedAffectedVersion($node['vulnerableVersionRange']);
                    continue;
                }

                $advisories[$remoteId] = new RemoteSecurityAdvisory(
                    $remoteId,
                    $node['advisory']['summary'],
                    $package->getName(),
                    $node['vulnerableVersionRange'],
                    $node['advisory']['permalink'],
                    $cve,
                    \DateTime::createFromFormat(\DateTimeInterface::ISO8601,  $node['advisory']['publishedAt']),
                    null
                );
            }

            $hasNextPage = $data['securityVulnerabilities']['pageInfo']['hasNextPage'];
            $after = $data['securityVulnerabilities']['pageInfo']['endCursor'];
        }

        return array_values($advisories);
    }

    private function getQuery(string $packageName, string $after = ''): string
    {
        if ('' !== $after) {
            $after = sprintf(',after:"%s"', $after);
        }

        $query = <<<QUERY
query {
  securityVulnerabilities(ecosystem:COMPOSER,package:"%s",first:100%s) {
    nodes {
      advisory {
        summary,
        permalink,
        publishedAt,
        identifiers {
          type,
          value
        }
      },
      vulnerableVersionRange
    },
    pageInfo {
      hasNextPage,
      endCursor
    }
  }
}
QUERY;

        return preg_replace('/[ \t\n]+/', '', sprintf($query, $packageName, $after));
    }
}