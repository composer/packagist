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

namespace App\Tests\Entity;

use App\Entity\Package;
use App\Entity\Vendor;
use App\Entity\VendorRepository;
use App\Tests\IntegrationTestCase;
use Predis\Client;

class VendorRepositoryTest extends IntegrationTestCase
{
    public function testVerifyClearsSuspectAndDropsTheVendorsViewCounters(): void
    {
        $suspect = self::createPackage('spamvendor/one', 'https://example.org/spamvendor/one');
        $suspect->setSuspect('Too many views');
        $innocent = self::createPackage('spamvendor/two', 'https://example.org/spamvendor/two');
        $unrelated = self::createPackage('othervendor/pkg', 'https://example.org/othervendor/pkg');
        $this->store($suspect, $innocent, $unrelated);

        $redis = $this->redis();
        $redis->mset([
            'views:'.$suspect->getId() => '120',
            'views:'.$innocent->getId() => '7',
            'views:'.$unrelated->getId() => '3',
        ]);

        $this->vendorRepo()->verify('spamvendor');

        self::assertNull($redis->get('views:'.$suspect->getId()));
        self::assertNull(
            $redis->get('views:'.$innocent->getId()),
            'a verified vendor is whitelisted wholesale, so none of its packages can be flagged again',
        );
        self::assertSame('3', $redis->get('views:'.$unrelated->getId()), 'other vendors must be left alone');

        $em = self::getEM();
        $em->clear();
        $reloaded = $em->find(Package::class, $suspect->getId());
        self::assertNotNull($reloaded);
        self::assertFalse($reloaded->isSuspect());
        self::assertTrue($this->vendorRepo()->isVerified('spamvendor'));

        $redis->del(['views:'.$unrelated->getId()]);
    }

    private function vendorRepo(): VendorRepository
    {
        $repo = self::getEM()->getRepository(Vendor::class);
        self::assertInstanceOf(VendorRepository::class, $repo);

        return $repo;
    }

    private function redis(): Client
    {
        $client = static::getContainer()->get('snc_redis.default');
        self::assertInstanceOf(Client::class, $client);

        return $client;
    }
}
