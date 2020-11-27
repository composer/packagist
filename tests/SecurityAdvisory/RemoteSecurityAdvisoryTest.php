<?php declare(strict_types=1);

namespace App\Tests\SecurityAdvisory;

use App\Entity\SecurityAdvisory;
use App\SecurityAdvisory\RemoteSecurityAdvisory;
use PHPUnit\Framework\TestCase;

class RemoteSecurityAdvisoryTest extends TestCase
{
    public function testCreateFromFriendsOfPhp(): void
    {
        $advisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('3f/pygmentize/2017-05-15.yaml', [
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

        $this->assertSame('3f/pygmentize/2017-05-15.yaml', $advisory->getId());
        $this->assertSame('Remote Code Execution', $advisory->getTitle());
        $this->assertSame('https://github.com/dedalozzo/pygmentize/issues/1', $advisory->getLink());
        $this->assertNull($advisory->getCve());
        $this->assertSame('<1.2', $advisory->getAffectedVersions());
        $this->assertSame('3f/pygmentize', $advisory->getPackageName());
        $this->assertSame('2017-05-15 00:00:00', $advisory->getDate()->format('Y-m-d H:i:s'));
        $this->assertSame(SecurityAdvisory::PACKAGIST_ORG, $advisory->getComposerRepository());
    }

    public function testCreateFromFriendsOfPhpOnlyYearAvailable(): void
    {
        $advisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('erusev/parsedown/CVE-2019-10905.yaml', [
            'title' => 'Class-Name Injection',
            'link' => 'https://github.com/erusev/parsedown/issues/699',
            'cve' => 'CVE-2019-10905',
            'branches' => [
                '1.0.x' => [
                    'time' => null,
                    'versions' => ['<1.7.2'],
                ],

            ],
            'reference' => 'composer://erusev/parsedown'
        ]);

        $this->assertSame('2019-01-01 00:00:00', $advisory->getDate()->format('Y-m-d H:i:s'));
    }

    public function testCreateFromFriendsOfPhpOnlyYearButBranchDatesAvailable(): void
    {
        $advisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('magento/magento1ee/CVE-2019-8114.yaml', [
            'title' => 'PRODSECBUG-2462: Remote code execution via file upload in admin import feature',
            'link' => 'https://magento.com/security/patches/magento-2.3.3-and-2.2.10-security-update',
            'cve' => 'CVE-2019-8114',
            'branches' => [
                '1' => [
                    'time' => 1570492800,
                    'versions' => ['>=1', '<1.14.4.3'],
                ],

            ],
            'reference' => 'composer://magento/magento1ee',
            'composer-repository' => false,
        ]);

        $this->assertSame('2019-10-08 00:00:00', $advisory->getDate()->format('Y-m-d H:i:s'));
    }
}
