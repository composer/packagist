<?php declare(strict_types=1);

namespace Packagist\WebBundle\Tests\SecurityAdvisory;

use Packagist\WebBundle\Entity\SecurityAdvisory;
use Packagist\WebBundle\SecurityAdvisory\RemoteSecurityAdvisory;
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
}
