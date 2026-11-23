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

namespace App\Tests\Validator;

use App\Entity\Package;
use App\Tests\IntegrationTestCase;
use App\Validator\ValidPackageRepository;
use Composer\Repository\Vcs\VcsDriverInterface;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * setRepository() runs the VCS driver over the network for https URLs, so name checks use a stubbed driver.
 */
class ValidPackageRepositoryValidatorTest extends IntegrationTestCase
{
    #[DataProvider('provideNonHttpsUrls')]
    public function testNonHttpsUrlsAreRejected(string $url): void
    {
        $package = new Package();
        $package->setRepository($url);

        $violations = self::getService(ValidatorInterface::class)->validate($package, new ValidPackageRepository());

        self::assertCount(1, $violations);
        self::assertSame('Only HTTPS repository URLs are supported, make sure your URL starts with https://', $violations[0]->getMessage());
    }

    #[DataProvider('provideReservedVendors')]
    public function testReservedVendorIsRejected(string $vendor): void
    {
        $violations = $this->validateName($vendor.'/foo');

        self::assertCount(1, $violations);
        self::assertSame('The vendor name '.$vendor.' is reserved, please use another name or reach out to us if you have a legitimate use for it.', $violations[0]->getMessage());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideReservedVendors(): iterable
    {
        yield 'vendor-only word' => ['sdk'];
        yield 'reserved username' => ['admin'];
        yield 'log actor label' => ['automation'];
    }

    public function testReservedUsernameIsAllowedAsExistingVendor(): void
    {
        $owner = self::createUser();
        $this->store($owner, self::createPackage('composer/existing', 'https://example.org/composer/existing', maintainers: [$owner]));

        self::assertCount(0, $this->validateName('composer/foo'));
    }

    public function testVendorOnlyReservedWordIsRejectedEvenIfVendorExists(): void
    {
        $owner = self::createUser();
        $this->store($owner, self::createPackage('sdk/existing', 'https://example.org/sdk/existing', maintainers: [$owner]));

        self::assertCount(1, $this->validateName('sdk/foo'));
    }

    private function validateName(string $name): ConstraintViolationListInterface
    {
        $driver = self::createStub(VcsDriverInterface::class);
        $driver->method('getRootIdentifier')->willReturn('main');
        $driver->method('getComposerInformation')->willReturn(['name' => $name]);

        $package = self::createPackage($name, 'https://example.org/'.$name);
        $package->vcsDriver = $driver;

        return self::getService(ValidatorInterface::class)->validate($package, new ValidPackageRepository());
    }

    /**
     * @return iterable<array{string}>
     */
    public static function provideNonHttpsUrls(): iterable
    {
        yield 'file' => ['file:///var/www/packagist/.git'];
        yield 'absolute path' => ['/var/www/packagist/.git'];
        yield 'http' => ['http://example.org/acme/foo.git'];
        yield 'git protocol' => ['git://example.org/acme/foo.git'];
        yield 'scp-style ssh' => ['git@example.org:acme/foo.git'];
        yield 'ssh' => ['ssh://git@example.org/acme/foo.git'];
        yield 'svn' => ['svn://example.org/acme/foo'];
        yield 'ftp' => ['ftp://example.org/acme/foo.git'];
    }
}
