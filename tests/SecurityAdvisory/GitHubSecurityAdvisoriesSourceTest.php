<?php

namespace App\Tests\SecurityAdvisory;

use App\Entity\Package;
use App\Entity\User;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use Composer\IO\BufferIO;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\TestCase;

/**
 * @author Yanick Witschi <yanick.witschi@terminal42.ch>
 */
class GitHubSecurityAdvisoriesSourceTest extends TestCase
{
    public function testNoAdvisoriesIfNoMaintainerHasAnyGitHubToken(): void
    {
        $client = $this->createMock(Client::class);

        $source = new GitHubSecurityAdvisoriesSource($client);
        $advisories = $source->getAdvisories(new BufferIO(), $this->getPackage());

        $this->assertSame([], $advisories);
    }

    public function testWithoutPagination(): void
    {
        $transactions = [];
        $history = Middleware::history($transactions);
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($this->getGraphQLResultFirstPage(false))),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);

        $source = new GitHubSecurityAdvisoriesSource($client);
        $advisories = $source->getAdvisories(new BufferIO(), $this->getPackage('github-token'));

        $this->assertCount(1, $transactions);
        $this->assertSame('POST', $transactions[0]['request']->getMethod());
        $this->assertSame('https://api.github.com/graphql', (string) $transactions[0]['request']->getUri());
        $this->assertSame('Bearer github-token', $transactions[0]['request']->getHeader('Authorization')[0]);
        $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,package:\"vendor\/package\",first:100){nodes{advisory{summary,permalink,publishedAt,identifiers{type,value}},vulnerableVersionRange},pageInfo{hasNextPage,endCursor}}}"}', $transactions[0]['request']->getBody()->getContents());

        $this->assertCount(2, $advisories);

        $this->assertSame('GHSA-h58v-c6rf-g9f7', $advisories[0]->getId());
        $this->assertSame('Cross site scripting in the system log', $advisories[0]->getTitle());
        $this->assertSame('vendor/package', $advisories[0]->getPackageName());
        $this->assertSame('>= 4.10.0, < 4.11.5|>= 4.5.0, < 4.9.16', $advisories[0]->getAffectedVersions());
        $this->assertSame('https://github.com/advisories/GHSA-h58v-c6rf-g9f7', $advisories[0]->getLink());
        $this->assertSame('CVE-2021-35210', $advisories[0]->getCve());
        $this->assertSame('2021-07-01T17:00:04+0000', $advisories[0]->getDate()->format(\DateTimeInterface::ISO8601));
        $this->assertSame(null, $advisories[0]->getComposerRepository());

        $this->assertSame('GHSA-f7wm-x4gw-6m23', $advisories[1]->getId());
        $this->assertSame('Insert tag injection in forms', $advisories[1]->getTitle());
        $this->assertSame('vendor/package', $advisories[1]->getPackageName());
        $this->assertSame('= 4.10.0', $advisories[1]->getAffectedVersions());
        $this->assertSame('https://github.com/advisories/GHSA-f7wm-x4gw-6m23', $advisories[1]->getLink());
        $this->assertSame('CVE-2020-25768', $advisories[1]->getCve());
        $this->assertSame('2020-09-24T16:23:54+0000', $advisories[1]->getDate()->format(\DateTimeInterface::ISO8601));
        $this->assertSame(null, $advisories[1]->getComposerRepository());
    }

    public function testWithPagination(): void
    {
        $transactions = [];
        $history = Middleware::history($transactions);
        $mock = new MockHandler([
            new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($this->getGraphQLResultFirstPage(true))),
            new Response(200, ['Content-Type' => 'application/json; charset=utf-8'], json_encode($this->getGraphQLResultSecondPage())),
        ]);
        $handlerStack = HandlerStack::create($mock);
        $handlerStack->push($history);
        $client = new Client(['handler' => $handlerStack]);

        $source = new GitHubSecurityAdvisoriesSource($client);
        $advisories = $source->getAdvisories(new BufferIO(), $this->getPackage('github-token'));

        $this->assertCount(2, $transactions);
        $this->assertSame('POST', $transactions[0]['request']->getMethod());
        $this->assertSame('https://api.github.com/graphql', (string) $transactions[0]['request']->getUri());
        $this->assertSame('Bearer github-token', $transactions[0]['request']->getHeader('Authorization')[0]);
        $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,package:\"vendor\/package\",first:100){nodes{advisory{summary,permalink,publishedAt,identifiers{type,value}},vulnerableVersionRange},pageInfo{hasNextPage,endCursor}}}"}', $transactions[0]['request']->getBody()->getContents());
        $this->assertSame('POST', $transactions[1]['request']->getMethod());
        $this->assertSame('https://api.github.com/graphql', (string) $transactions[1]['request']->getUri());
        $this->assertSame('Bearer github-token', $transactions[1]['request']->getHeader('Authorization')[0]);
        $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,package:\"vendor\/package\",first:100,after:\"Y3Vyc29yOnYyOpK5MjAyMC0wOS0yNFQxODoyMzo0MyswMjowMM0T9A==\"){nodes{advisory{summary,permalink,publishedAt,identifiers{type,value}},vulnerableVersionRange},pageInfo{hasNextPage,endCursor}}}"}', $transactions[1]['request']->getBody()->getContents());

        $this->assertCount(2, $advisories);

        $this->assertSame('GHSA-h58v-c6rf-g9f7', $advisories[0]->getId());
        $this->assertSame('Cross site scripting in the system log', $advisories[0]->getTitle());
        $this->assertSame('vendor/package', $advisories[0]->getPackageName());
        $this->assertSame('>= 4.10.0, < 4.11.5|>= 4.5.0, < 4.9.16', $advisories[0]->getAffectedVersions());
        $this->assertSame('https://github.com/advisories/GHSA-h58v-c6rf-g9f7', $advisories[0]->getLink());
        $this->assertSame('CVE-2021-35210', $advisories[0]->getCve());
        $this->assertSame('2021-07-01T17:00:04+0000', $advisories[0]->getDate()->format(\DateTimeInterface::ISO8601));
        $this->assertSame(null, $advisories[0]->getComposerRepository());

        $this->assertSame('GHSA-f7wm-x4gw-6m23', $advisories[1]->getId());
        $this->assertSame('Insert tag injection in forms', $advisories[1]->getTitle());
        $this->assertSame('vendor/package', $advisories[1]->getPackageName());
        $this->assertSame('= 4.10.0|< 4.11.0', $advisories[1]->getAffectedVersions());
        $this->assertSame('https://github.com/advisories/GHSA-f7wm-x4gw-6m23', $advisories[1]->getLink());
        $this->assertSame('CVE-2020-25768', $advisories[1]->getCve());
        $this->assertSame('2020-09-24T16:23:54+0000', $advisories[1]->getDate()->format(\DateTimeInterface::ISO8601));
        $this->assertSame(null, $advisories[1]->getComposerRepository());
    }


    private function getPackage(string $githubToken = null): Package
    {
        $user = new User();
        $user->setGithubToken($githubToken);

        $package = new Package();
        $package->setName('vendor/package');
        $package->addMaintainer($user);

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
                            ],
                            'vulnerableVersionRange' => '>= 4.10.0, < 4.11.5',
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
                            ],
                            'vulnerableVersionRange' => '>= 4.5.0, < 4.9.16',
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
                            ],
                            'vulnerableVersionRange' => '= 4.10.0',
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
                            ],
                            'vulnerableVersionRange' => '< 4.11.0',
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