<?php declare(strict_types=1);

namespace App\Tests\Entity;

use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use PHPUnit\Framework\TestCase;

class SecurityAdvisoryTest extends TestCase
{
    public function testCalculateDifferenceScore(): void
    {
        $remoteAdvisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('3f/pygmentize/2017-05-15.yaml', [
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
        ]);

        $advisory = new SecurityAdvisory($remoteAdvisory, 'source');

        $this->assertSame(0, $advisory->calculateDifferenceScore($remoteAdvisory));
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
}
