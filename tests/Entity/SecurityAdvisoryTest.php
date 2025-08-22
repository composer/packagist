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

namespace App\Tests\Entity;

use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\FriendsOfPhpSecurityAdvisoriesSource;
use App\SecurityAdvisory\GitHubSecurityAdvisoriesSource;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use App\SecurityAdvisory\Severity;
use PHPUnit\Framework\TestCase;

class SecurityAdvisoryTest extends TestCase
{
    public function testCalculateDifferenceScore(): void
    {
        $data = [
            'title' => 'Remote Code Execution',
            'link' => 'https://github.com/dedalozzo/pygmentize/issues/1',
            'cve' => null,
            'branches' => [
                '1.x' => [
                    'time' => 1494806400,
                    'versions' => ['<1.2'],
                ],
            ],
            'reference' => 'composer://3f/pygmentize',
        ];

        $remoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('3f/pygmentize/2017-05-15.yaml', $data);
        $updatedAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('3f/pygmentize/2017-05-15.yaml', $data);

        $advisory = new SecurityAdvisory($remoteAdvisory, 'source');

        $this->assertSame(0, $advisory->calculateDifferenceScore($updatedAdvisory));
    }

    public function testCalculateDifferenceScoreChangeNameAndCVE(): void
    {
        $remoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('league/flysystem/2021-06-24.yaml', [
            'title' => 'TOCTOU Race Condition enabling remote code execution',
            'link' => 'https://github.com/thephpleague/flysystem/security/advisories/GHSA-9f46-5r25-5wfm',
            'cve' => null,
            'branches' => [
                '1.x' => [
                    'time' => '2021-06-23 23:56:59',
                    'versions' => ['<1.1.4'],
                ],
                '2.x' => [
                    'time' => '2021-06-24 00:07:59',
                    'versions' => ['>=2.0.0', '<2.1.1'],
                ],
            ],
            'reference' => 'composer://league/flysystem',
        ]);

        $advisory = new SecurityAdvisory($remoteAdvisory, FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);

        $updatedRemoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('league/flysystem/CVE-2021-32708.yaml', [
            'title' => 'TOCTOU Race Condition enabling remote code execution',
            'link' => 'https://github.com/thephpleague/flysystem/security/advisories/GHSA-9f46-5r25-5wfm',
            'cve' => 'CVE-2021-32708',
            'branches' => [
                '1.x' => [
                    'time' => '2021-06-23 23:56:59',
                    'versions' => ['<1.1.4'],
                ],
                '2.x' => [
                    'time' => '2021-06-24 00:07:59',
                    'versions' => ['>=2.0.0', '<2.1.1'],
                ],
            ],
            'reference' => 'composer://league/flysystem',
        ]);

        $this->assertSame(3, $advisory->calculateDifferenceScore($updatedRemoteAdvisory));
    }

    public function testCalculateDifferenceScoreCveXXXX(): void
    {
        $remoteAdvisory = $this->generateFriendsOfPhpRemoteAdvisory('CVE-2022-xxxx: CSRF token missing in forms', 'https://symfony.com/cve-2022-xxxx', 'CVE-2022-xxxx');

        $advisory = new SecurityAdvisory($remoteAdvisory, FriendsOfPhpSecurityAdvisoriesSource::SOURCE_NAME);

        $updatedRemoteAdvisory = $this->generateFriendsOfPhpRemoteAdvisory('CVE-2022-99999999999: CSRF token missing in forms', 'https://symfony.com/cve-2022-99999999999', 'CVE-2022-99999999999');

        $this->assertSame(3, $advisory->calculateDifferenceScore($updatedRemoteAdvisory));
    }

    public function testStoreSeverity(): void
    {
        $friendsOfPhpRemoteAdvisory = $this->generateFriendsOfPhpRemoteAdvisory('CVE-2022-xxxx: CSRF token missing in forms', 'https://symfony.com/cve-2022-xxxx', 'CVE-2022-xxxx');
        $gitHubRemoteAdvisor = $this->generateGitHubAdvisory(null);
        $advisory = new SecurityAdvisory($friendsOfPhpRemoteAdvisory, $friendsOfPhpRemoteAdvisory->source);

        $this->assertNull($advisory->getSeverity(), "FriendsOfPHP doesn't provide severity information");
        $advisory->addSource($gitHubRemoteAdvisor->id, GitHubSecurityAdvisoriesSource::SOURCE_NAME, null);
        $advisory->updateAdvisory($this->generateGitHubAdvisory(Severity::HIGH));
        $this->assertSame(Severity::HIGH, $advisory->getSeverity(), 'GitHub should update the advisory severity');
        $this->assertSame(Severity::HIGH, $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getSeverity(), 'GitHub should update the source data');

        $advisory->updateAdvisory($this->generateGitHubAdvisory(Severity::MEDIUM));
        $this->assertSame(Severity::MEDIUM, $advisory->getSeverity(), 'GitHub should update the advisory severity');
        $this->assertSame(Severity::MEDIUM, $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getSeverity(), 'GitHub should update the source data');

        $advisory->updateAdvisory($friendsOfPhpRemoteAdvisory);
        $this->assertSame(Severity::MEDIUM, $advisory->getSeverity(), "FriendsOfPHP shouldn't reset the severity information");

        $advisory->updateAdvisory($this->generateGitHubAdvisory(Severity::HIGH));
        $this->assertSame(Severity::HIGH, $advisory->getSeverity(), 'GitHub should update the advisory severity');
        $this->assertSame(Severity::HIGH, $advisory->findSecurityAdvisorySource(GitHubSecurityAdvisoriesSource::SOURCE_NAME)?->getSeverity(), 'GitHub should update the source data');
    }

    private function generateGitHubAdvisory(?Severity $severity): RemoteSecurityAdvisory
    {
        return new RemoteSecurityAdvisory(
            'GHSA-1234-1234-1234',
            'Tile',
            'symfony/framework-bundle',
            '',
            'https://github.com/advisories/GHSA-1234-1234-1234',
            null,
            new \DateTimeImmutable(),
            null,
            [],
            GitHubSecurityAdvisoriesSource::SOURCE_NAME,
            $severity,
        );
    }

    private function generateFriendsOfPhpRemoteAdvisory(string $title, string $link, string $cve): RemoteSecurityAdvisory
    {
        return RemoteSecurityAdvisory::createFromFriendsOfPhp(\sprintf('symfony/framework-bundle/%s.yaml', $cve), [
            'title' => $title,
            'link' => $link,
            'cve' => $cve,
            'branches' => [
                '5.3.x' => [
                    'time' => '2022-01-29 12:00:00',
                    'versions' => ['>=5.3.14', '<=5.3.14'],
                ],
                '5.4.x' => [
                    'time' => '2022-01-29 12:00:00',
                    'versions' => ['>=5.4.3', '<=5.4.3'],
                ],
                '6.0.x' => [
                    'time' => '2022-01-29 12:00:00',
                    'versions' => ['>=6.0.3', '<=6.0.3'],
                ],
            ],
            'reference' => 'composer://symfony/framework-bundle',
        ]);
    }
}
