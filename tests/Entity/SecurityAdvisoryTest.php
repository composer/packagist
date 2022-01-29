<?php declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
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
            'reference' => 'composer://3f/pygmentize'
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
            'reference' => 'composer://league/flysystem'
        ]);

        $advisory = new SecurityAdvisory($remoteAdvisory, 'source');

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
            'reference' => 'composer://league/flysystem'
        ]);

        $this->assertSame(3, $advisory->calculateDifferenceScore($updatedRemoteAdvisory));
    }

    public function testCalculateDifferenceScoreCveXXXX(): void
    {
        $remoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('symfony/framework-bundle/CVE-2022-xxxx.yaml', [
            'title' => 'CVE-2022-xxxx: CSRF token missing in forms',
            'link' => 'https://symfony.com/cve-2022-xxxx',
            'cve' => 'CVE-2022-xxxx',
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
            'reference' => 'composer://symfony/framework-bundle'
        ]);

        $advisory = new SecurityAdvisory($remoteAdvisory, 'source');

        $updatedRemoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('symfony/framework-bundle/CVE-2022-99999999999.yaml', [
            'title' => 'CVE-2022-99999999999: CSRF token missing in forms',
            'link' => 'https://symfony.com/cve-2022-99999999999',
            'cve' => 'CVE-2022-99999999999',
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
            'reference' => 'composer://symfony/framework-bundle'
        ]);

        $this->assertSame(3, $advisory->calculateDifferenceScore($updatedRemoteAdvisory));
    }
}
