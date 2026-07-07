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

namespace App\Tests\Command;

use App\Command\TriageSpamQueueCommand;
use App\Entity\Package;
use App\Entity\Vendor;
use App\Service\Locker;
use App\Service\Spam\FeatureExtractor;
use App\Service\Spam\SpamClassifier;
use App\Tests\IntegrationTestCase;
use Doctrine\Persistence\ManagerRegistry;
use Psr\Log\NullLogger;
use Symfony\Component\Console\Tester\CommandTester;

class TriageSpamQueueCommandTest extends IntegrationTestCase
{
    // Fixture weights: name token "widget" is strongly safe, "spam" strongly spammy (threshold 0.5).
    private const FIXTURE = __DIR__.'/../Fixtures/spam-model.json';

    private function tester(string $modelFile): CommandTester
    {
        $container = self::getContainer();
        $command = new TriageSpamQueueCommand(
            $container->get(ManagerRegistry::class),
            new SpamClassifier(new FeatureExtractor(), new NullLogger(), $modelFile),
            $container->get(Locker::class),
            new NullLogger(),
            $container->get(\Graze\DogStatsD\Client::class),
        );

        return new CommandTester($command);
    }

    private function seedSuspect(string $name): Package
    {
        $package = self::createPackage($name, 'https://example.org/'.$name);
        $package->setSuspect('Too many views');
        $this->store($package);

        return $package;
    }

    private function isSuspect(int $id): bool
    {
        $em = self::getEM();
        $em->clear();
        $package = $em->find(Package::class, $id);
        self::assertNotNull($package);

        return $package->isSuspect();
    }

    private function isVerified(string $vendor): bool
    {
        return self::getEM()->getRepository(Vendor::class)->isVerified($vendor);
    }

    public function testNoModelAvailableIsANoop(): void
    {
        $safe = $this->seedSuspect('safevendor/widget');

        $tester = $this->tester('/no/such/spam-model.json');
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('No spam model available', $tester->getDisplay());
        $this->assertTrue($this->isSuspect($safe->getId()), 'nothing may be cleared without a model');
        $this->assertFalse($this->isVerified('safevendor'));
    }

    public function testDryRunReportsWithoutMutating(): void
    {
        $safe = $this->seedSuspect('safevendor/widget');

        $tester = $this->tester(self::FIXTURE);
        $tester->execute([]);

        $tester->assertCommandIsSuccessful();
        $this->assertStringContainsString('Would clear', $tester->getDisplay());
        $this->assertTrue($this->isSuspect($safe->getId()), 'dry run must not clear anything');
        $this->assertFalse($this->isVerified('safevendor'));
    }

    public function testApplyClearsOnlyVendorsThatAreEntirelySafe(): void
    {
        $safe = $this->seedSuspect('safevendor/widget');
        $spam = $this->seedSuspect('spamvendor/spam');
        $mixedSafe = $this->seedSuspect('mixedvendor/widget');
        $mixedSpam = $this->seedSuspect('mixedvendor/spam');

        $tester = $this->tester(self::FIXTURE);
        $tester->execute(['--apply' => true]);

        $tester->assertCommandIsSuccessful();

        // Entirely-safe vendor is cleared + verified.
        $this->assertFalse($this->isSuspect($safe->getId()));
        $this->assertTrue($this->isVerified('safevendor'));

        // Spammy vendor is left in the queue.
        $this->assertTrue($this->isSuspect($spam->getId()));
        $this->assertFalse($this->isVerified('spamvendor'));

        // Mixed vendor: one spammy package means the whole vendor is left for a human.
        $this->assertTrue($this->isSuspect($mixedSafe->getId()));
        $this->assertTrue($this->isSuspect($mixedSpam->getId()));
        $this->assertFalse($this->isVerified('mixedvendor'));
    }
}
