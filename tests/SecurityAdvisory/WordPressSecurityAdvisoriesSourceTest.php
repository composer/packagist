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
use App\SecurityAdvisory\WordPressSecurityAdvisoriesSource;
use App\SecurityAdvisory\Severity;
use Composer\IO\BufferIO;
use Doctrine\Persistence\ManagerRegistry;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * @author Léo Colombaro <git@colombaro.fr>
 */
class WordPressSecurityAdvisoriesSourceTest extends TestCase
{
    private ProviderManager&Stub $providerManager;
    private ManagerRegistry&Stub $doctrine;

    protected function setUp(): void
    {
        $this->providerManager = $this->createStub(ProviderManager::class);
        $this->doctrine = $this->createStub(ManagerRegistry::class);
    }

    public function testWithoutPagination(): void
    {
        $responseFactory = function (string $method, string $url, array $options) {
            $this->assertSame('GET', $method);
            $this->assertStringStartsWith('https://api.github.com/repos/WordPress/wordpress-develop/security-advisories', $url);

            return new MockResponse(json_encode($this->getApiResultFirstPage(false)), ['http_code' => 200, 'response_headers' => ['Content-Type' => 'application/json; charset=utf-8']]);
        };
        $client = new MockHttpClient($responseFactory);

        $source = new WordPressSecurityAdvisoriesSource($client, new NullLogger(), $this->providerManager, [], $this->doctrine);
        $package = $this->getPackage();
        $advisoryCollection = $source->getAdvisories(new BufferIO());

        $this->assertNotNull($advisoryCollection);
        $this->assertSame(1, $client->getRequestsCount());

        $advisories = $advisoryCollection->getAdvisoriesForPackageName($package->getName());
        $this->assertCount(2, $advisories);

        $this->assertSame('GHSA-h58v-c6rf-g9f7', $advisories[0]->id);
        $this->assertSame('Cross site scripting in the system log', $advisories[0]->title);
        $this->assertSame('wordpress/core-implementation', $advisories[0]->packageName);
        $this->assertSame('>=4.10.0,<4.11.5|7.1.0-7.1', $advisories[0]->affectedVersions);
        $this->assertSame('https://github.com/advisories/GHSA-h58v-c6rf-g9f7', $advisories[0]->link);
        $this->assertSame('CVE-2021-35210', $advisories[0]->cve);
        $this->assertSame('2021-07-01T17:00:04+0000', $advisories[0]->date->format(\DateTimeInterface::ISO8601));
        $this->assertNull($advisories[0]->composerRepository);
        $this->assertSame(Severity::MEDIUM, $advisories[0]->severity);

        $this->assertSame('GHSA-f7wm-x4gw-6m23', $advisories[1]->id);
        $this->assertSame('Insert tag injection in forms', $advisories[1]->title);
        $this->assertSame('wordpress/core-implementation', $advisories[1]->packageName);
        $this->assertSame('=4.10.0|7.1.0-7.1', $advisories[1]->affectedVersions);
        $this->assertSame('https://github.com/advisories/GHSA-f7wm-x4gw-6m23', $advisories[1]->link);
        $this->assertSame('CVE-2020-25768', $advisories[1]->cve);
        $this->assertSame('2020-09-24T16:23:54+0000', $advisories[1]->date->format(\DateTimeInterface::ISO8601));
        $this->assertNull($advisories[1]->composerRepository);
        $this->assertSame(Severity::MEDIUM, $advisories[1]->severity);
    }

    private function getPackage(): Package
    {
        $package = new Package();
        $package->setName('roots/wordpress-no-content');

        return $package;
    }

    private function getApiResultFirstPage(bool $hasNextPage): array
    {
        return [
            $this->apiPackageNode('GHSA-h58v-c6rf-g9f7', '>= 4.10.0, < 4.11.5', 'CVE-2021-35210', 'Cross site scripting in the system log', '2021-07-01T17:00:04Z'),
            $this->apiPackageNode('GHSA-f7wm-x4gw-6m23', '= 4.10.0', 'CVE-2020-25768', 'Insert tag injection in forms', '2020-09-24T16:23:54Z'),
        ];
    }

    private function apiPackageNode(string $advisoryId, string $range, string $cve, string $summary, string $publishedAt, ?string $withdrawnAt = null): array
    {
        return [
            'summary' => $summary,
            'html_url' => 'https://github.com/advisories/'.$advisoryId,
            'published_at' => $publishedAt,
            'withdrawn_at' => $withdrawnAt,
            'severity' => 'medium',
            'identifiers' => [
                ['type' => 'GHSA', 'value' => $advisoryId],
                ['type' => 'CVE', 'value' => $cve],
            ],
            'vulnerabilities' => [
                [
                    'package' => [
                        'ecosystem' => 'other',
                        'name' => 'WordPress',
                    ],
                    'vulnerable_version_range' => $range,
                    'patched_versions' => '7.1.1',
                    'vulnerable_functions' => [],
                ],
                [
                    'package' => [
                        'ecosystem' => 'wordpress',
                        'name' => null,
                    ],
                    'vulnerable_version_range' => '7.1.0 - 7.1',
                    'patched_versions' => '7.1.1',
                    'vulnerable_functions' => [],
                ],
            ],
        ];
    }
}
