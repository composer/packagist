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
use App\Model\DownloadManager;
use App\Validator\PopularPackageSafety;
use App\Validator\PopularPackageSafetyValidator;
use Doctrine\Persistence\ManagerRegistry;
use Predis\Connection\ConnectionException;
use Predis\Connection\NodeConnectionInterface;
use Symfony\Bundle\SecurityBundle\Security;
use Symfony\Component\Validator\ConstraintValidatorInterface;
use Symfony\Component\Validator\Test\ConstraintValidatorTestCase;

/**
 * @extends ConstraintValidatorTestCase<PopularPackageSafetyValidator>
 */
class PopularPackageSafetyValidatorTest extends ConstraintValidatorTestCase
{
    private DownloadManager $downloadManager;

    protected function createValidator(): ConstraintValidatorInterface
    {
        $this->downloadManager = $this->createStub(DownloadManager::class);

        return new PopularPackageSafetyValidator(
            $this->downloadManager,
            $this->createStub(Security::class),
            $this->createStub(ManagerRegistry::class),
        );
    }

    public function testPopularPackageIsBlockedWithItsOwnCode(): void
    {
        $this->downloadManager->method('getTotalDownloads')->willReturn(50_001);

        $constraint = new PopularPackageSafety();
        $this->validator->validate($this->package(), $constraint);

        $this->buildViolation($constraint->message)
            ->atPath('property.path.repository')
            ->setCode(PopularPackageSafety::POPULAR_PACKAGE_ERROR)
            ->assertRaised();
    }

    /**
     * PackageController offers the support workflow off POPULAR_PACKAGE_ERROR only, so a Redis
     * outage must not produce that code.
     */
    public function testUnreadableDownloadCountIsBlockedWithADistinctCode(): void
    {
        $this->downloadManager->method('getTotalDownloads')
            ->willThrowException(new ConnectionException($this->createStub(NodeConnectionInterface::class), 'down'));

        $constraint = new PopularPackageSafety();
        $this->validator->validate($this->package(), $constraint);

        $this->buildViolation($constraint->unknownMessage)
            ->atPath('property.path.repository')
            ->setCode(PopularPackageSafety::UNKNOWN_POPULARITY_ERROR)
            ->assertRaised();
    }

    public function testUnpopularPackageIsNotBlocked(): void
    {
        $this->downloadManager->method('getTotalDownloads')->willReturn(50_000);

        $this->validator->validate($this->package(), new PopularPackageSafety());

        $this->assertNoViolation();
    }

    private function package(): Package
    {
        $package = new Package();
        // bypasses setRepository(), which probes the URL
        new \ReflectionProperty($package, 'repository')->setValue($package, 'https://example.org/acme/pkg');

        return $package;
    }
}
