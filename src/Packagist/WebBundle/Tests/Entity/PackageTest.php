<?php

namespace Packagist\WebBundle\Tests\Entity;

use Composer\Repository\Vcs\VcsDriverInterface;
use Packagist\WebBundle\Entity\Package;
use Prophecy\Argument;
use ReflectionObject;
use Symfony\Component\Validator\Context\ExecutionContextInterface;
use Symfony\Component\Validator\Violation\ConstraintViolationBuilderInterface;

class PackageTest extends \PHPUnit_Framework_TestCase
{
    public function provideValidPackages()
    {
        $packages = [
            'composer/composer',
            'api-clients/skeleton',
            'react/http-client',
            'wyrihaximus/☼',
            'elephpants/🌈-🐘',
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
            yield $packageName => [
                $this->createPackage($packageName),
            ];
        }
    }

    public function provideInvalidPackages()
    {
        $invalidPackageNameMessage = 'The package name %s is invalid, it should have a vendor name, a forward slash, and a package name. The vendor and package name can be words separated by -, . or _. The complete name should match "[a-z0-9]([_.-]?[a-z0-9]+)*/[a-z0-9]([_.-]?[a-z0-9]+)*".';
        $packages = [
            'CVE-2006-6459' => sprintf($invalidPackageNameMessage, 'CVE-2006-6459'),
            '☼/🔥' => sprintf($invalidPackageNameMessage, '☼/🔥'),
            './.' => sprintf($invalidPackageNameMessage, './.'),
        ];

        foreach ($packages as $packageName => $expectedException) {
            yield $packageName => [
                $this->createPackage($packageName),
                $expectedException
            ];
        }
    }

    protected function createPackage(string $packageName): Package
    {
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

        return $package;
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

    /**
     * @dataProvider provideInvalidPackages
     */
    public function testIsRepositoryInvalid(Package $package, string $exceptionMessage)
    {
        $executionContextLevel3 = $this->prophesize(ConstraintViolationBuilderInterface::class);

        $executionContextLevel2 = $this->prophesize(ConstraintViolationBuilderInterface::class);
        $executionContextLevel2->atPath(Argument::any())->willReturn($executionContextLevel3->reveal());

        $executionContext = $this->prophesize(ExecutionContextInterface::class);
        $executionContext->buildViolation($exceptionMessage)->shouldBeCalled()->willReturn($executionContextLevel2->reveal());
        $package->isRepositoryValid($executionContext->reveal());
    }
}
