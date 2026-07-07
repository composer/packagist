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

namespace App\Tests\Service\Spam;

use App\Service\Spam\FeatureExtractor;
use App\Service\Spam\SpamClassifier;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class SpamClassifierTest extends TestCase
{
    private const FIXTURE = __DIR__.'/../../Fixtures/spam-model.json';

    private const CLEAN_README = '<p>A great logging library for PHP applications</p>';
    private const SPAMMY_README = '<p>Best deals <a href="https://casino.example/win">buy now</a></p>';

    private function classifier(string $modelFile): SpamClassifier
    {
        return new SpamClassifier(new FeatureExtractor(), new NullLogger(), $modelFile);
    }

    public function testModelAvailabilityDependsOnTheFile(): void
    {
        $this->assertTrue($this->classifier(self::FIXTURE)->isModelAvailable());
        $this->assertFalse($this->classifier('')->isModelAvailable(), 'empty path disables the classifier');
        $this->assertFalse($this->classifier('/no/such/spam-model.json')->isModelAvailable());
    }

    public function testMetadataProbabilitySeparatesSpamFromSafe(): void
    {
        $classifier = $this->classifier(self::FIXTURE);

        $this->assertGreaterThan(0.9, $classifier->predictSpamProbability('evil/spam', null, []));
        $this->assertLessThan(0.1, $classifier->predictSpamProbability('acme/widget', null, []));
    }

    public function testSafeMetadataWithoutReadmeIsConfidentlySafe(): void
    {
        $this->assertTrue($this->classifier(self::FIXTURE)->isConfidentlySafe('acme/widget', null, []));
    }

    public function testSpammyMetadataIsNotSafe(): void
    {
        $this->assertFalse($this->classifier(self::FIXTURE)->isConfidentlySafe('evil/spam', null, []));
    }

    public function testSpammyReadmeVetoesAnOtherwiseSafePackage(): void
    {
        $classifier = $this->classifier(self::FIXTURE);

        // Same metadata-safe package: safe with no readme / a clean readme, but vetoed by a spammy one.
        $this->assertTrue($classifier->isConfidentlySafe('acme/widget', null, [], null));
        $this->assertTrue($classifier->isConfidentlySafe('acme/widget', null, [], self::CLEAN_README));
        $this->assertFalse($classifier->isConfidentlySafe('acme/widget', null, [], self::SPAMMY_README));
    }

    public function testReadmeProbabilitySeparatesSpamFromClean(): void
    {
        $classifier = $this->classifier(self::FIXTURE);

        $this->assertGreaterThan(0.9, $classifier->predictReadmeSpamProbability(self::SPAMMY_README));
        $this->assertLessThan(0.1, $classifier->predictReadmeSpamProbability(self::CLEAN_README));
    }

    public function testScoringThrowsWhenNoModelIsAvailable(): void
    {
        $classifier = $this->classifier('/no/such/spam-model.json');

        $this->expectException(\LogicException::class);
        $classifier->isConfidentlySafe('acme/widget', null, []);
    }
}
