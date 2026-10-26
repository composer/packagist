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
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Only non-https URLs are covered, as setRepository() runs the VCS driver over the network for https ones.
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
