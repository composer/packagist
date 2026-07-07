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
use PHPUnit\Framework\TestCase;

class FeatureExtractorTest extends TestCase
{
    private FeatureExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new FeatureExtractor();
    }

    public function testMetadataTokensCoverNameDescriptionAndTags(): void
    {
        $tokens = $this->extractor->metadataTokens('acme/widget-kit', 'A great logging widget', ['php', 'Logging']);

        // name words (prefixed n:)
        $this->assertContains('n:acme', $tokens);
        $this->assertContains('n:widget', $tokens);
        $this->assertContains('n:kit', $tokens);

        // description words + bigrams (prefixed d:), short word "a" dropped
        $this->assertContains('d:great', $tokens);
        $this->assertContains('d:logging', $tokens);
        $this->assertContains('d:great logging', $tokens);
        $this->assertNotContains('d:a', $tokens);

        // tags, lowercased (prefixed t:)
        $this->assertContains('t:php', $tokens);
        $this->assertContains('t:logging', $tokens);

        // character n-grams of the raw name (prefixed nc:)
        $this->assertContains('nc:acm', $tokens);
        $this->assertContains('nc:widg', $tokens);
    }

    public function testMetadataTokensAreDeterministicAndDeduplicated(): void
    {
        $first = $this->extractor->metadataTokens('foo/bar', 'repeat repeat repeat', ['x', 'x']);
        $second = $this->extractor->metadataTokens('foo/bar', 'repeat repeat repeat', ['x', 'x']);

        $this->assertSame($first, $second, 'tokenization must be deterministic (protects against train/serve skew)');
        $this->assertSame(array_values(array_unique($first)), $first, 'tokens must be de-duplicated');
    }

    public function testMetadataTokensHandleNullDescription(): void
    {
        $tokens = $this->extractor->metadataTokens('foo/bar', null, []);

        $this->assertContains('n:foo', $tokens);
        $this->assertContains('n:bar', $tokens);
    }

    public function testReadmeTokensExtractLinkHostsAndCountBucket(): void
    {
        $html = '<p>Cheap deals <a href="https://casino.example/win">click here</a> and '
            .'<img src="http://tracker.example/pixel.gif"> now</p>';

        $tokens = $this->extractor->readmeTokens($html);

        $this->assertContains('rl:casino.example', $tokens);
        $this->assertContains('rl:tracker.example', $tokens);
        $this->assertContains('rlc:1-2', $tokens, '2 links should land in the 1-2 bucket');
        $this->assertContains('r:cheap', $tokens);
        $this->assertContains('r:click here', $tokens);
    }

    public function testReadmeWithoutLinksReportsZeroBucket(): void
    {
        $tokens = $this->extractor->readmeTokens('<p>Just a plain readme with no links at all</p>');

        $this->assertContains('rlc:0', $tokens);
        $this->assertContains('r:plain', $tokens);
        // No link host tokens
        $this->assertSame([], array_values(array_filter($tokens, static fn (string $t) => str_starts_with($t, 'rl:'))));
    }
}
