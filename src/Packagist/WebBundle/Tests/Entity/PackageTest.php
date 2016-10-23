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
            'composer/composer', // Actual package
            'api-clients/skeleton', // Actual package
            'react/http-client', // Actual package
            'wyrihaximus/☼', // The package I'm doing this PR for
            'elephpants/🌈-🐘', // https://twitter.com/RainbowLePHPant
            'japan/象の虹', // Rainbow Elephant in Japanese
            'vietnam/voi-cầu-vồng', // Rainbow Elephant in Vietnamese
            'china-traditional/大象彩虹', // Rainbow Elephant in Traditional Chinese
            'russia/слон-радуги', // Rainbow Elephant in Russian
            'the-netherlands/0226', // WyriHaximus' area code
            '31/20', // Amsterdam area code
            'the.dot/the.dot', // Just the dot
            'rdohms/c.hash', // https://youtu.be/wYccKQBy26Q?t=3m24s
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
            'CVE-2006-6459' => sprintf($invalidPackageNameMessage, 'CVE-2006-6459'), // Register globals is bad, WyriHaximus learned it the hardway
            '☼/🔥' => sprintf($invalidPackageNameMessage, '☼/🔥'), // Sun burn
            './.' => sprintf($invalidPackageNameMessage, './.'), // Just dots
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
        $executionContextLevel3 = $this->prophesize(ConstraintViolationBuilderInterface::class);

        $executionContextLevel2 = $this->prophesize(ConstraintViolationBuilderInterface::class);
        $executionContextLevel2->atPath(Argument::any())->willReturn($executionContextLevel3->reveal());

        $executionContext = $this->prophesize(ExecutionContextInterface::class);
        $executionContext->buildViolation(Argument::any())->shouldNotBeCalled()->willReturn($executionContextLevel2->reveal());
        $package->isRepositoryValid($executionContext->reveal());
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
