<?php

namespace Packagist\WebBundle\Tests\Entity;

use Composer\Repository\Vcs\VcsDriverInterface;
use Packagist\WebBundle\Entity\Package;
use Prophecy\Argument;
use ReflectionObject;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

class PackageTest extends \PHPUnit_Framework_TestCase
{
    public function provideValidPackages()
    {
        $packages = [
            'composer/composer',
            'api-clients/skeleton',
            'react/http-client',
            'wyrihaximus/☼',
            'elephpants/?-?',
            'japan/象の虹',
            'vietnam/voi-cầu-vồng',
            'china-traditional/大象彩虹',
            'china-traditional/слон-радуги',
            'the-netherlands/0226',
            '31/20',
            'the.dot/the.dot',
            'rdohms/c.hash',
        ];

        foreach ($packages as $packageName) {
            $package = new Package();
            $package->setName($packageName);

            $vcsDriver = $this->prophesize(VcsDriverInterface::class);
            $vcsDriver->getComposerInformation('master')->shouldBeCalled()->willReturn([
                'name' => $packageName,
            ]);
            $vcsDriver->getRootIdentifier()->shouldBeCalled()->willReturn('master');


            $r = new ReflectionObject($package);
            $p = $r->getProperty('vcsDriver');
            $p->setAccessible(true);
            $p->setValue($package, $vcsDriver->reveal());

            yield $packageName => [
                $package,
            ];
        }
    }

    /**
     * @dataProvider provideValidPackages
     */
    public function testIsRepositoryValid(Package $package)
    {
        $executionContext = $this->prophesize(ExecutionContextInterface::class);
        $package->isRepositoryValid($executionContext->reveal());
        $this->assertTrue(true, 'No exception occurred');
    }
}
