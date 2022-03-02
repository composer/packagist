<?php declare(strict_types=1);

namespace App\Tests\SecurityAdvisory;

use App\Entity\Package;
use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use Composer\IO\BufferIO;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author Yanick Witschi <yanick.witschi@terminal42.ch>
 */
class GitHubSecurityAdvisoriesSourceTest extends TestCase
{
    public function testWithoutPagination(): void
    {
        $responseFactory = function (string $method, string $url, array $options) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.github.com/graphql', $url);
            $this->assertArrayHasKey('authorization', $options['normalized_headers']);
            $this->assertSame(['Authorization: token token'], $options['normalized_headers']['authorization']);
            $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,first:100){nodes{advisory{summary,permalink,publishedAt,withdrawnAt,identifiers{type,value},references{url}},vulnerableVersionRange,package{name}},pageInfo{hasNextPage,endCursor}}}"}', $options['body']);

            return new MockResponse(json_encode($this->getGraphQLResultFirstPage(false)),['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
        };
        $client = new MockHttpClient($responseFactory);

        $source = new GitHubSecurityAdvisoriesSource($client, new NullLogger(), ['token']);
        $package = $this->getPackage();
        $advisoryCollection = $source->getAdvisories(new BufferIO());

        $this->assertNotNull($advisoryCollection);
        $this->assertSame(1, $client->getRequestsCount());

        $advisories = $advisoryCollection->getAdvisoriesForPackageName($package->getName());
        $this->assertCount(2, $advisories);

        $this->assertSame('GHSA-h58v-c6rf-g9f7', $advisories[0]->getId());
        $this->assertSame('Cross site scripting in the system log', $advisories[0]->getTitle());
        $this->assertSame('vendor/package', $advisories[0]->getPackageName());
        $this->assertSame('>=4.10.0,<4.11.5|>=4.5.0,<4.9.16', $advisories[0]->getAffectedVersions());
        $this->assertSame('https://github.com/advisories/GHSA-h58v-c6rf-g9f7', $advisories[0]->getLink());
        $this->assertSame('CVE-2021-35210', $advisories[0]->getCve());
        $this->assertSame('2021-07-01T17:00:04+0000', $advisories[0]->getDate()->format(\DateTimeInterface::ISO8601));
        $this->assertSame(SecurityAdvisory::PACKAGIST_ORG, $advisories[0]->getComposerRepository());

        $this->assertSame('GHSA-f7wm-x4gw-6m23', $advisories[1]->getId());
        $this->assertSame('Insert tag injection in forms', $advisories[1]->getTitle());
        $this->assertSame('vendor/package', $advisories[1]->getPackageName());
        $this->assertSame('=4.10.0', $advisories[1]->getAffectedVersions());
        $this->assertSame('https://github.com/advisories/GHSA-f7wm-x4gw-6m23', $advisories[1]->getLink());
        $this->assertSame('CVE-2020-25768', $advisories[1]->getCve());
        $this->assertSame('2020-09-24T16:23:54+0000', $advisories[1]->getDate()->format(\DateTimeInterface::ISO8601));
        $this->assertSame(SecurityAdvisory::PACKAGIST_ORG, $advisories[1]->getComposerRepository());
    }

    public function testWithPagination(): void
    {
        $counter = 0;
        $responseFactory = function (string $method, string $url, array $options) use (&$counter) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.github.com/graphql', $url);
            $this->assertArrayHasKey('authorization', $options['normalized_headers']);
            $this->assertSame(['Authorization: token token'], $options['normalized_headers']['authorization']);

            $counter++;
            switch ($counter) {
                case 1:
                    $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,first:100){nodes{advisory{summary,permalink,publishedAt,withdrawnAt,identifiers{type,value},references{url}},vulnerableVersionRange,package{name}},pageInfo{hasNextPage,endCursor}}}"}', $options['body']);

                    return new MockResponse(json_encode($this->getGraphQLResultFirstPage(true)),['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
                case 2:
                    $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,first:100,after:\u0022Y3Vyc29yOnYyOpK5MjAyMC0wOS0yNFQxODoyMzo0MyswMjowMM0T9A==\u0022){nodes{advisory{summary,permalink,publishedAt,withdrawnAt,identifiers{type,value},references{url}},vulnerableVersionRange,package{name}},pageInfo{hasNextPage,endCursor}}}"}', $options['body']);

                    return new MockResponse(json_encode($this->getGraphQLResultSecondPage()),['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
            }


            return new MockResponse(json_encode($this->getGraphQLResultFirstPage(false)),['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
        };

        $client = new MockHttpClient($responseFactory);
        $source = new GitHubSecurityAdvisoriesSource($client, new NullLogger(), ['token']);
        $package = $this->getPackage();
        $advisoryCollection = $source->getAdvisories(new BufferIO());

        $this->assertNotNull($advisoryCollection);
        $this->assertSame(2, $client->getRequestsCount());

        $advisories = $advisoryCollection->getAdvisoriesForPackageName($package->getName());
        $this->assertCount(2, $advisories);

        $this->assertSame('GHSA-h58v-c6rf-g9f7', $advisories[0]->getId());
        $this->assertSame('Cross site scripting in the system log', $advisories[0]->getTitle());
        $this->assertSame('vendor/package', $advisories[0]->getPackageName());
        $this->assertSame('>=4.10.0,<4.11.5|>=4.5.0,<4.9.16', $advisories[0]->getAffectedVersions());
        $this->assertSame('https://github.com/advisories/GHSA-h58v-c6rf-g9f7', $advisories[0]->getLink());
        $this->assertSame('CVE-2021-35210', $advisories[0]->getCve());
        $this->assertSame('2021-07-01T17:00:04+0000', $advisories[0]->getDate()->format(\DateTimeInterface::ISO8601));
        $this->assertSame(SecurityAdvisory::PACKAGIST_ORG, $advisories[0]->getComposerRepository());

        $this->assertSame('GHSA-f7wm-x4gw-6m23', $advisories[1]->getId());
        $this->assertSame('Insert tag injection in forms', $advisories[1]->getTitle());
        $this->assertSame('vendor/package', $advisories[1]->getPackageName());
        $this->assertSame('=4.10.0|<4.11.0', $advisories[1]->getAffectedVersions());
        $this->assertSame('https://github.com/advisories/GHSA-f7wm-x4gw-6m23', $advisories[1]->getLink());
        $this->assertSame('CVE-2020-25768', $advisories[1]->getCve());
        $this->assertSame('2020-09-24T16:23:54+0000', $advisories[1]->getDate()->format(\DateTimeInterface::ISO8601));
        $this->assertSame(SecurityAdvisory::PACKAGIST_ORG, $advisories[1]->getComposerRepository());
    }


    private function getPackage(): Package
    {
        $package = new Package();
        $package->setName('vendor/package');

        return $package;
    }

    private function getGraphQLResultFirstPage(bool $hasNextPage): array
    {
        return [
            'data' => [
                'securityVulnerabilities' => [
                    'nodes' => [
                        [
                            'advisory' => [
                                'summary' => 'Cross site scripting in the system log',
                                'permalink' => 'https://github.com/advisories/GHSA-h58v-c6rf-g9f7',
                                'publishedAt' => '2021-07-01T17:00:04Z',
                                'identifiers' => [
                                    ['type' => 'GHSA', 'value' => 'GHSA-h58v-c6rf-g9f7',],
                                    ['type' => 'CVE', 'value' => 'CVE-2021-35210'],
                                ],
                                'references' => [],
                            ],
                            'vulnerableVersionRange' => '>= 4.10.0, < 4.11.5',
                            'package' => [
                                'name' => 'vendor/package',
                            ],
                        ],
                        [
                            'advisory' => [
                                'summary' => 'Cross site scripting in the system log',
                                'permalink' => 'https://github.com/advisories/GHSA-h58v-c6rf-g9f7',
                                'publishedAt' => '2021-07-01T17:00:04Z',
                                'identifiers' => [
                                    ['type' => 'GHSA', 'value' => 'GHSA-h58v-c6rf-g9f7'],
                                    ['type' => 'CVE', 'value' => 'CVE-2021-35210'],
                                ],
                                'references' => [],
                            ],
                            'vulnerableVersionRange' => '>= 4.5.0, < 4.9.16',
                            'package' => [
                                'name' => 'vendor/package',
                            ],
                        ],
                        [
                            'advisory' => [
                                'summary' => 'Insert tag injection in forms',
                                'permalink' => 'https://github.com/advisories/GHSA-f7wm-x4gw-6m23',
                                'publishedAt' => '2020-09-24T16:23:54Z',
                                'identifiers' => [
                                    ['type' => 'GHSA', 'value' => 'GHSA-f7wm-x4gw-6m23',],
                                    ['type' => 'CVE', 'value' => 'CVE-2020-25768'],
                                ],
                                'references' => [],
                            ],
                            'vulnerableVersionRange' => '= 4.10.0',
                            'package' => [
                                'name' => 'vendor/package',
                            ],
                        ],
                    ],
                    'pageInfo' => [
                        'hasNextPage' => $hasNextPage,
                        'endCursor' => 'Y3Vyc29yOnYyOpK5MjAyMC0wOS0yNFQxODoyMzo0MyswMjowMM0T9A==',
                    ],
                ],
            ],
        ];
    }

    private function getGraphQLResultSecondPage(): array
    {
        return [
            'data' => [
                'securityVulnerabilities' => [
                    'nodes' => [
                        [
                            'advisory' => [
                                'summary' => 'Insert tag injection in forms',
                                'permalink' => 'https://github.com/advisories/GHSA-f7wm-x4gw-6m23',
                                'publishedAt' => '2020-09-24T16:23:54Z',
                                'identifiers' => [
                                    ['type' => 'GHSA', 'value' => 'GHSA-f7wm-x4gw-6m23',],
                                    ['type' => 'CVE', 'value' => 'CVE-2020-25768'],
                                ],
                                'references' => [],
                            ],
                            'vulnerableVersionRange' => '< 4.11.0',
                            'package' => [
                                'name' => 'vendor/package',
                            ],
                        ],
                    ],
                    'pageInfo' => [
                        'hasNextPage' => false,
                        'endCursor' => 'Y3Vyc29yOnYyOpK5MjAxOS0xMi0xN1QyMDozNTozMSswMTowMM0LWQ==',
                    ],
                ],
            ],
        ];
    }
}
