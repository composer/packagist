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
            'reference' => 'composer://3f/pygmentize',
        ]);

        $this->assertSame('3f/pygmentize/2017-05-15.yaml', $advisory->id);
        $this->assertSame('Remote Code Execution', $advisory->title);
        $this->assertSame('https://github.com/dedalozzo/pygmentize/issues/1', $advisory->link);
        $this->assertNull($advisory->cve);
        $this->assertSame('<1.2', $advisory->affectedVersions);
        $this->assertSame('3f/pygmentize', $advisory->packageName);
        $this->assertSame('2017-05-15 00:00:00', $advisory->date->format('Y-m-d H:i:s'));
        $this->assertSame(SecurityAdvisory::PACKAGIST_ORG, $advisory->composerRepository);
        $this->assertNull($advisory->severity);
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
            'reference' => 'composer://erusev/parsedown',
        ]);

        $this->assertSame('2019-01-01 00:00:00', $advisory->date->format('Y-m-d H:i:s'));
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

        $this->assertSame('2019-10-08 00:00:00', $advisory->date->format('Y-m-d H:i:s'));
    }

    public function testCreateFromFriendsOfPhpCVEXXXX(): void
    {
        $advisory = RemoteSecurityAdvisory::createFromFriendsOfPhp('symfony/framework-bundle/CVE-2022-xxxx.yaml', [
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
            'reference' => 'composer://symfony/framework-bundle',
        ]);

        $this->assertSame('symfony/framework-bundle/CVE-2022-xxxx.yaml', $advisory->id);
        $this->assertNull($advisory->cve);
    }

    public function testWithAddedAffectedVersion(): void
    {
        $advisory = new RemoteSecurityAdvisory('id', 'foobar', 'foo/bar', '>=1', 'https://foobar.com', null, new \DateTimeImmutable(), null, [], 'test', null);
        $advisory = $advisory->withAddedAffectedVersion('<2');

        $this->assertSame('>=1|<2', $advisory->affectedVersions);
    }
}
