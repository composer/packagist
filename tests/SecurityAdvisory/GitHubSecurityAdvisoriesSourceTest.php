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

namespace App\Tests\SecurityAdvisory;

use App\Entity\Package;
use App\Entity\SecurityAdvisory;
use App\Model\ProviderManager;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use App\SecurityAdvisory\Severity;
use Composer\IO\BufferIO;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author Yanick Witschi <yanick.witschi@terminal42.ch>
 */
class GitHubSecurityAdvisoriesSourceTest extends TestCase
{
    private ProviderManager&MockObject $providerManager;
    private ManagerRegistry&MockObject $doctrine;

    protected function setUp(): void
    {
        $this->providerManager = $this->createMock(ProviderManager::class);
        $this->doctrine = $this->createMock(ManagerRegistry::class);
    }

    public function testWithoutPagination(): void
    {
        $responseFactory = function (string $method, string $url, array $options) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.github.com/graphql', $url);
            $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,first:100){nodes{advisory{summary,permalink,publishedAt,withdrawnAt,severity,identifiers{type,value},references{url}},vulnerableVersionRange,package{name}},pageInfo{hasNextPage,endCursor}}}"}', $options['body']);

            return new MockResponse(json_encode($this->getGraphQLResultFirstPage(false)), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
        };
        $client = new MockHttpClient($responseFactory);

        $source = new GitHubSecurityAdvisoriesSource($client, new NullLogger(), $this->providerManager, [], $this->doctrine);
        $package = $this->getPackage();
        $advisoryCollection = $source->getAdvisories(new BufferIO());

        $this->assertNotNull($advisoryCollection);
        $this->assertSame(1, $client->getRequestsCount());

        $advisories = $advisoryCollection->getAdvisoriesForPackageName($package->getName());
        $this->assertCount(2, $advisories);

        $this->assertSame('GHSA-h58v-c6rf-g9f7', $advisories[0]->id);
        $this->assertSame('Cross site scripting in the system log', $advisories[0]->title);
        $this->assertSame('vendor/package', $advisories[0]->packageName);
        $this->assertSame('>=4.10.0,<4.11.5|>=4.5.0,<4.9.16', $advisories[0]->affectedVersions);
        $this->assertSame('https://github.com/advisories/GHSA-h58v-c6rf-g9f7', $advisories[0]->link);
        $this->assertSame('CVE-2021-35210', $advisories[0]->cve);
        $this->assertSame('2021-07-01T17:00:04+0000', $advisories[0]->date->format(\DateTimeInterface::ISO8601));
        $this->assertNull($advisories[0]->composerRepository);
        $this->assertSame(Severity::MEDIUM, $advisories[0]->severity);

        $this->assertSame('GHSA-f7wm-x4gw-6m23', $advisories[1]->id);
        $this->assertSame('Insert tag injection in forms', $advisories[1]->title);
        $this->assertSame('vendor/package', $advisories[1]->packageName);
        $this->assertSame('=4.10.0', $advisories[1]->affectedVersions);
        $this->assertSame('https://github.com/advisories/GHSA-f7wm-x4gw-6m23', $advisories[1]->link);
        $this->assertSame('CVE-2020-25768', $advisories[1]->cve);
        $this->assertSame('2020-09-24T16:23:54+0000', $advisories[1]->date->format(\DateTimeInterface::ISO8601));
        $this->assertNull($advisories[1]->composerRepository);
        $this->assertSame(Severity::MEDIUM, $advisories[1]->severity);
    }

    public function testWithPagination(): void
    {
        $this->providerManager
            ->method('packageExists')
            ->willReturnMap([
                ['vendor/package', true],
                ['vendor/other-package', false],
            ]);

        $counter = 0;
        $responseFactory = function (string $method, string $url, array $options) use (&$counter) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.github.com/graphql', $url);

            $counter++;
            switch ($counter) {
                case 1:
                    $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,first:100){nodes{advisory{summary,permalink,publishedAt,withdrawnAt,severity,identifiers{type,value},references{url}},vulnerableVersionRange,package{name}},pageInfo{hasNextPage,endCursor}}}"}', $options['body']);

                    return new MockResponse(json_encode($this->getGraphQLResultFirstPage(true)), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
                case 2:
                    $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,first:100,after:\u0022Y3Vyc29yOnYyOpK5MjAyMC0wOS0yNFQxODoyMzo0MyswMjowMM0T9A==\u0022){nodes{advisory{summary,permalink,publishedAt,withdrawnAt,severity,identifiers{type,value},references{url}},vulnerableVersionRange,package{name}},pageInfo{hasNextPage,endCursor}}}"}', $options['body']);

                    return new MockResponse(json_encode($this->getGraphQLResultSecondPage()), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
            }

            return new MockResponse(json_encode($this->getGraphQLResultFirstPage(false)), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
        };

        $client = new MockHttpClient($responseFactory);
        $source = new GitHubSecurityAdvisoriesSource($client, new NullLogger(), $this->providerManager, [], $this->doctrine);
        $package = $this->getPackage();
        $advisoryCollection = $source->getAdvisories(new BufferIO());

        $this->assertNotNull($advisoryCollection);
        $this->assertSame(2, $client->getRequestsCount());

        $otherAdvisories = $advisoryCollection->getAdvisoriesForPackageName('vendor/other-package');
        $this->assertCount(1, $otherAdvisories);
        $this->assertSame('<5.11.0', $otherAdvisories[0]->affectedVersions);

        $advisories = $advisoryCollection->getAdvisoriesForPackageName($package->getName());
        $this->assertCount(2, $advisories);

        $this->assertSame('GHSA-h58v-c6rf-g9f7', $advisories[0]->id);
        $this->assertSame('Cross site scripting in the system log', $advisories[0]->title);
        $this->assertSame('vendor/package', $advisories[0]->packageName);
        $this->assertSame('>=4.10.0,<4.11.5|>=4.5.0,<4.9.16', $advisories[0]->affectedVersions);
        $this->assertSame('https://github.com/advisories/GHSA-h58v-c6rf-g9f7', $advisories[0]->link);
        $this->assertSame('CVE-2021-35210', $advisories[0]->cve);
        $this->assertSame('2021-07-01T17:00:04+0000', $advisories[0]->date->format(\DateTimeInterface::ISO8601));
        $this->assertSame(SecurityAdvisory::PACKAGIST_ORG, $advisories[0]->composerRepository);
        $this->assertSame(Severity::MEDIUM, $advisories[0]->severity);

        $this->assertSame('GHSA-f7wm-x4gw-6m23', $advisories[1]->id);
        $this->assertSame('Insert tag injection in forms', $advisories[1]->title);
        $this->assertSame('vendor/package', $advisories[1]->packageName);
        $this->assertSame('=4.10.0|<4.11.0', $advisories[1]->affectedVersions);
        $this->assertSame('https://github.com/advisories/GHSA-f7wm-x4gw-6m23', $advisories[1]->link);
        $this->assertSame('CVE-2020-25768', $advisories[1]->cve);
        $this->assertSame('2020-09-24T16:23:54+0000', $advisories[1]->date->format(\DateTimeInterface::ISO8601));
        $this->assertSame(SecurityAdvisory::PACKAGIST_ORG, $advisories[1]->composerRepository);
        $this->assertSame(Severity::MEDIUM, $advisories[1]->severity);
    }

    public function testDuplicateCvePerPackage(): void
    {
        $responseFactory = function (string $method, string $url, array $options) {
            $this->assertSame('POST', $method);
            $this->assertSame('https://api.github.com/graphql', $url);
            $this->assertSame('{"query":"query{securityVulnerabilities(ecosystem:COMPOSER,first:100){nodes{advisory{summary,permalink,publishedAt,withdrawnAt,severity,identifiers{type,value},references{url}},vulnerableVersionRange,package{name}},pageInfo{hasNextPage,endCursor}}}"}', $options['body']);

            return new MockResponse(json_encode($this->getGraphQLResultPageWithMultipleCvePerPackage()), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
        };
        $client = new MockHttpClient($responseFactory);

        $source = new GitHubSecurityAdvisoriesSource($client, new NullLogger(), $this->providerManager, [], $this->doctrine);
        $package = $this->getPackage();
        $advisoryCollection = $source->getAdvisories(new BufferIO());

        $this->assertNotNull($advisoryCollection);
        $this->assertSame(1, $client->getRequestsCount());

        $advisories = $advisoryCollection->getAdvisoriesForPackageName($package->getName());
        $this->assertCount(1, $advisories);

        $this->assertSame('GHSA-h58v-c6rf-abcd', $advisories[0]->id);
        $this->assertSame('Insert tag injection', $advisories[0]->title);
        $this->assertSame('vendor/package', $advisories[0]->packageName);
        $this->assertSame('=4.10.0', $advisories[0]->affectedVersions);
        $this->assertSame('https://github.com/advisories/GHSA-h58v-c6rf-abcd', $advisories[0]->link);
        $this->assertSame('CVE-2020-25768', $advisories[0]->cve);
        $this->assertSame('2021-07-01T17:00:04+0000', $advisories[0]->date->format(\DateTimeInterface::ISO8601));
        $this->assertNull($advisories[0]->composerRepository);
        $this->assertSame(Severity::MEDIUM, $advisories[0]->severity);
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
                        $this->graphQlPackageNode('GHSA-h58v-c6rf-g9f7', 'vendor/package', '>= 4.10.0, < 4.11.5', 'CVE-2021-35210', 'Cross site scripting in the system log', '2021-07-01T17:00:04Z'),
                        $this->graphQlPackageNode('GHSA-h58v-c6rf-g9f7', 'vendor/package', '>= 4.5.0, < 4.9.16', 'CVE-2021-35210', 'Cross site scripting in the system log', '2021-07-01T17:00:04Z'),
                        $this->graphQlPackageNode('GHSA-f7wm-x4gw-6m23', 'vendor/package', '= 4.10.0', 'CVE-2020-25768', 'Insert tag injection in forms', '2020-09-24T16:23:54Z'),
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
                        $this->graphQlPackageNode('GHSA-f7wm-x4gw-6m23', 'vendor/package', '< 4.11.0', 'CVE-2020-25768', 'Insert tag injection in forms', '2020-09-24T16:23:54Z'),
                        $this->graphQlPackageNode('GHSA-f7wm-x4gw-6m23', 'vendor/other-package', '< 5.11.0', 'CVE-2020-25768', 'Insert tag injection in forms', '2020-09-24T16:23:54Z'),
                    ],
                    'pageInfo' => [
                        'hasNextPage' => false,
                        'endCursor' => 'Y3Vyc29yOnYyOpK5MjAxOS0xMi0xN1QyMDozNTozMSswMTowMM0LWQ==',
                    ],
                ],
            ],
        ];
    }

    private function getGraphQLResultPageWithMultipleCvePerPackage(): array
    {
        return [
            'data' => [
                'securityVulnerabilities' => [
                    'nodes' => [
                        $this->graphQlPackageNode('GHSA-h58v-c6rf-abcd', 'vendor/package', '= 4.10.0', 'CVE-2020-25768', 'Insert tag injection', '2021-07-01T17:00:04Z'),
                        $this->graphQlPackageNode('GHSA-f7wm-x4gw-abcd', 'vendor/package', '= 4.10.0', 'CVE-2020-25768', 'Insert tag injection in forms', '2020-09-24T16:23:54Z'),
                    ],
                    'pageInfo' => [
                        'hasNextPage' => false,
                        'endCursor' => 'Y3Vyc29yOnYyOpK5MjAxOS0xMi0xN1QyMDozNTozMSswMTowMM0LWQ==',
                    ],
                ],
            ],
        ];
    }

    private function graphQlPackageNode(string $advisoryId, string $packageName, string $range, string $cve, string $summary, string $publishedAt): array
    {
        return [
            'advisory' => [
                'summary' => $summary,
                'permalink' => 'https://github.com/advisories/' . $advisoryId,
                'publishedAt' => $publishedAt,
                'withdrawnAt' => null,
                'severity' => 'MODERATE',
                'identifiers' => [
                    ['type' => 'GHSA', 'value' => $advisoryId],
                    ['type' => 'CVE', 'value' => $cve],
                ],
                'references' => [],
            ],
            'vulnerableVersionRange' => $range,
            'package' => [
                'name' => $packageName,
            ],
        ];
    }
}
