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

namespace App\Service\Spam;

use Composer\Pcre\Preg;

/**
 * Turns a package into a set of binary feature tokens for the spam classifier.
 *
 * This is the single source of truth for tokenization: it is used both when building the
 * training dataset (see BuildSpamFeaturesCommand) and at inference time (see SpamClassifier).
 * The Python training step never sees raw text, only the token lists produced here, so the
 * features are guaranteed to be identical between training and serving.
 *
 * Everything here must stay deterministic (no randomness, no locale/time dependence). Tokens are
 * prefixed per source so the two models' vocabularies never collide:
 *   n:  name word          nc: name character n-gram      d: description word/bigram
 *   t:  tag                r:  readme word/bigram          rl: readme link host   rlc: link-count bucket
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class FeatureExtractor
{
    private const CHAR_NGRAM_MIN = 3;
    private const CHAR_NGRAM_MAX = 4;
    private const MIN_WORD_LENGTH = 2;

    /**
     * Pass 1 features: package name, description and tags. Always available.
     *
     * @param list<string> $tags
     *
     * @return list<string>
     */
    public function metadataTokens(string $name, ?string $description, array $tags): array
    {
        $tokens = [];

        $lowerName = mb_strtolower($name);
        foreach ($this->words($lowerName) as $word) {
            $tokens[] = 'n:'.$word;
        }
        // Character n-grams over the raw (lowercased) name catch keyword-stuffed / gibberish names
        // like "cheap-seo-backlinks-buy" that word tokens alone would miss.
        foreach ($this->charNgrams($lowerName) as $ngram) {
            $tokens[] = 'nc:'.$ngram;
        }

        if ($description !== null && $description !== '') {
            $words = $this->words(mb_strtolower($description));
            foreach ($words as $word) {
                $tokens[] = 'd:'.$word;
            }
            foreach ($this->bigrams($words) as $bigram) {
                $tokens[] = 'd:'.$bigram;
            }
        }

        foreach ($tags as $tag) {
            $tag = mb_strtolower(trim($tag));
            if ($tag !== '') {
                $tokens[] = 't:'.$tag;
            }
        }

        return $this->dedupe($tokens);
    }

    /**
     * Pass 2 features: readme text. Only used when a package actually has a readme, so its
     * presence/absence is never itself a feature (avoiding the "missing readme = safe" leak).
     *
     * @param string $readmeHtml the stored, sanitized README HTML (PackageReadme::$contents)
     *
     * @return list<string>
     */
    public function readmeTokens(string $readmeHtml): array
    {
        $tokens = [];

        // Harvest link hosts before stripping tags: spam READMEs are typically link farms, so both
        // the destination hosts and the sheer link density are strong signals.
        $linkCount = 0;
        if (Preg::matchAll('{(?:href|src)\s*=\s*["\']([^"\']+)["\']}i', $readmeHtml, $matches) > 0) {
            foreach ($matches[1] as $url) {
                $linkCount++;
                $host = parse_url(trim($url), PHP_URL_HOST);
                if (\is_string($host) && $host !== '') {
                    $tokens[] = 'rl:'.mb_strtolower($host);
                }
            }
        }
        $tokens[] = 'rlc:'.$this->linkBucket($linkCount);

        $text = html_entity_decode(strip_tags($readmeHtml), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $words = $this->words(mb_strtolower($text));
        foreach ($words as $word) {
            $tokens[] = 'r:'.$word;
        }
        foreach ($this->bigrams($words) as $bigram) {
            $tokens[] = 'r:'.$bigram;
        }

        return $this->dedupe($tokens);
    }

    /**
     * Split an already-lowercased string into alphanumeric word tokens, dropping very short ones.
     *
     * @return list<string>
     */
    private function words(string $lower): array
    {
        $words = [];
        foreach (Preg::split('{[^a-z0-9]+}', $lower) as $word) {
            if (\strlen($word) >= self::MIN_WORD_LENGTH) {
                $words[] = $word;
            }
        }

        return $words;
    }

    /**
     * @param list<string> $words
     *
     * @return list<string>
     */
    private function bigrams(array $words): array
    {
        $bigrams = [];
        $count = \count($words);
        for ($i = 1; $i < $count; $i++) {
            $bigrams[] = $words[$i - 1].' '.$words[$i];
        }

        return $bigrams;
    }

    /**
     * @return list<string>
     */
    private function charNgrams(string $lower): array
    {
        $ngrams = [];
        $length = \strlen($lower);
        for ($size = self::CHAR_NGRAM_MIN; $size <= self::CHAR_NGRAM_MAX; $size++) {
            for ($offset = 0; $offset + $size <= $length; $offset++) {
                $ngrams[] = substr($lower, $offset, $size);
            }
        }

        return $ngrams;
    }

    private function linkBucket(int $count): string
    {
        return match (true) {
            $count === 0 => '0',
            $count <= 2 => '1-2',
            $count <= 9 => '3-9',
            $count <= 29 => '10-29',
            default => '30+',
        };
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<string>
     */
    private function dedupe(array $tokens): array
    {
        return array_values(array_unique($tokens));
    }
}
