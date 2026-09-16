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
use App\Entity\User;
use App\Tests\IntegrationTestCase;
use App\Validator\VendorWritable;
use Symfony\Component\Validator\ConstraintViolationListInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates the single constraint rather than the Create group, so none of the other submit-time
 * validators (which mail people and hit the network) run.
 */
class VendorWritableValidatorTest extends IntegrationTestCase
{
    public function testVendorClaimedBySomeoneElseIsRejected(): void
    {
        [$stranger, $package] = $this->setUpTakenVendor();

        $package->addMaintainer($stranger);

        self::assertCount(1, $this->validate($package));
    }

    public function testWaivingTheCheckLetsAModeratorClaimTheVendor(): void
    {
        [$stranger, $package] = $this->setUpTakenVendor();

        $package->addMaintainer($stranger);
        $package->waiveVendorOwnershipCheck();

        self::assertCount(0, $this->validate($package));
    }

    public function testSubmittingOnBehalfOfAUserMakesThemTheOnlyMaintainer(): void
    {
        [$stranger, $package] = $this->setUpTakenVendor();
        $moderator = self::createUser('moderator', 'moderator@example.org', githubId: '4', roles: ['ROLE_EDIT_PACKAGES']);
        $this->store($moderator);

        $package->addMaintainer($moderator);
        $package->setSubmittedOnBehalfOf($stranger);

        self::assertSame([$stranger], $package->getMaintainers()->toArray());
        self::assertSame($stranger, $package->getSubmittedOnBehalfOf());
    }

    /**
     * @return array{User, Package}
     */
    private function setUpTakenVendor(): array
    {
        $owner = self::createUser('owner', 'owner@example.org', githubId: '2');
        $stranger = self::createUser('stranger', 'stranger@example.org', githubId: '3');
        $this->store($owner, $stranger, self::createPackage('acme/existing', 'https://example.org/acme/existing', maintainers: [$owner]));

        return [$stranger, self::createPackage('acme/newpkg', 'https://example.org/acme/newpkg')];
    }

    private function validate(Package $package): ConstraintViolationListInterface
    {
        return self::getService(ValidatorInterface::class)->validate($package, new VendorWritable());
    }
}
