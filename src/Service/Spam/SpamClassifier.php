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

use Psr\Log\LoggerInterface;

/**
 * Pure-PHP inference for the spam classifier.
 *
 * The trained weights are never committed to the repo (they are kept private so the scoring cannot
 * be reverse-engineered and gamed). The model file lives only on the prod server; its path comes
 * from the SPAM_MODEL_FILE env var. When the file is absent or invalid the classifier is a no-op:
 * isModelAvailable() returns false and callers must not clear anything. Actually scoring while the
 * model is unavailable is a bug, so the scoring methods throw rather than silently pass.
 *
 * @phpstan-type SpamModelSection array{bias: float, threshold: float, weights: array<string, float>}
 * @phpstan-type SpamModel array{metadata: SpamModelSection, readme: SpamModelSection}
 *
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class SpamClassifier
{
    private bool $loaded = false;

    /** @var SpamModel|null */
    private ?array $model = null;

    public function __construct(
        private readonly FeatureExtractor $featureExtractor,
        private readonly LoggerInterface $logger,
        private readonly string $spamModelFile,
    ) {
    }

    /**
     * True once a valid model file is present and parseable. When false the classifier is disabled
     * and must not be used to clear packages.
     */
    public function isModelAvailable(): bool
    {
        return $this->load() !== null;
    }

    /**
     * Probability (0..1) that the metadata (name/description/tags) looks like spam.
     *
     * @param list<string> $tags
     */
    public function predictSpamProbability(string $name, ?string $description, array $tags): float
    {
        $model = $this->requireModel();

        return $this->score($model['metadata'], $this->featureExtractor->metadataTokens($name, $description, $tags));
    }

    /**
     * Probability (0..1) that the README looks like spam.
     */
    public function predictReadmeSpamProbability(string $readmeHtml): float
    {
        $model = $this->requireModel();

        return $this->score($model['readme'], $this->featureExtractor->readmeTokens($readmeHtml));
    }

    /**
     * Whether a package can be auto-cleared: the metadata must score below its safe threshold AND,
     * when a README is present, the README must also score below its threshold. A spammy README
     * therefore vetoes a clear, while a missing README is never held against the package.
     *
     * @param list<string> $tags
     */
    public function isConfidentlySafe(string $name, ?string $description, array $tags, ?string $readmeHtml = null): bool
    {
        return $this->evaluate($name, $description, $tags, $readmeHtml)['safe'];
    }

    /**
     * Score a package in a single pass: the metadata spam probability, the README spam probability
     * (null when there is no README), and the resulting auto-clear verdict. Handy for surfacing the
     * scores in the review UI without recomputing.
     *
     * @param list<string> $tags
     *
     * @return array{metadata: float, readme: float|null, safe: bool}
     */
    public function evaluate(string $name, ?string $description, array $tags, ?string $readmeHtml = null): array
    {
        $model = $this->requireModel();

        $metadataProbability = $this->score($model['metadata'], $this->featureExtractor->metadataTokens($name, $description, $tags));
        $safe = $metadataProbability < $model['metadata']['threshold'];

        $readmeProbability = null;
        if ($readmeHtml !== null && $readmeHtml !== '') {
            $readmeProbability = $this->score($model['readme'], $this->featureExtractor->readmeTokens($readmeHtml));
            if ($readmeProbability >= $model['readme']['threshold']) {
                $safe = false;
            }
        }

        return ['metadata' => $metadataProbability, 'readme' => $readmeProbability, 'safe' => $safe];
    }

    /**
     * @param SpamModelSection $section
     * @param list<string>     $tokens
     */
    private function score(array $section, array $tokens): float
    {
        $sum = $section['bias'];
        $weights = $section['weights'];
        foreach ($tokens as $token) {
            if (isset($weights[$token])) {
                $sum += $weights[$token];
            }
        }

        // Logistic sigmoid, guarding against exp() overflow at the extremes.
        if ($sum <= -60.0) {
            return 0.0;
        }
        if ($sum >= 60.0) {
            return 1.0;
        }

        return 1.0 / (1.0 + exp(-$sum));
    }

    /**
     * @return SpamModel
     */
    private function requireModel(): array
    {
        $model = $this->load();
        if ($model === null) {
            throw new \LogicException('Spam model is not available (SPAM_MODEL_FILE unset, missing or invalid); check isModelAvailable() before scoring.');
        }

        return $model;
    }

    /**
     * @return SpamModel|null
     */
    private function load(): ?array
    {
        if ($this->loaded) {
            return $this->model;
        }
        $this->loaded = true;

        if ($this->spamModelFile === '' || !is_file($this->spamModelFile)) {
            return null;
        }

        $mtime = @filemtime($this->spamModelFile);
        if ($mtime === false) {
            return null;
        }

        $useApcu = \function_exists('apcu_enabled') && apcu_enabled();
        $apcuKey = 'spam_model:'.$this->spamModelFile.':'.$mtime;
        if ($useApcu) {
            /** @var SpamModel|false $cached */
            $cached = apcu_fetch($apcuKey, $success);
            if ($success === true && \is_array($cached)) {
                $this->model = $cached;

                return $cached;
            }
        }

        $parsed = $this->parse($this->spamModelFile);
        if ($parsed === null) {
            return null;
        }

        if ($useApcu) {
            apcu_store($apcuKey, $parsed, 3600);
        }
        $this->model = $parsed;

        return $parsed;
    }

    /**
     * @return SpamModel|null
     */
    private function parse(string $file): ?array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) {
            $this->logger->warning('Spam model file could not be read', ['file' => $file]);

            return null;
        }

        try {
            $decoded = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            $this->logger->warning('Spam model file contains invalid JSON', ['file' => $file, 'exception' => $e]);

            return null;
        }

        if (!\is_array($decoded) || !isset($decoded['metadata'], $decoded['readme'])) {
            $this->logger->warning('Spam model file is missing the metadata/readme sections', ['file' => $file]);

            return null;
        }

        $metadata = $this->parseSection($decoded['metadata']);
        $readme = $this->parseSection($decoded['readme']);
        if ($metadata === null || $readme === null) {
            $this->logger->warning('Spam model file has a malformed metadata/readme section', ['file' => $file]);

            return null;
        }

        return ['metadata' => $metadata, 'readme' => $readme];
    }

    /**
     * @return SpamModelSection|null
     */
    private function parseSection(mixed $section): ?array
    {
        if (!\is_array($section) || !isset($section['bias'], $section['threshold'], $section['weights'])) {
            return null;
        }
        if (!is_numeric($section['bias']) || !is_numeric($section['threshold']) || !\is_array($section['weights'])) {
            return null;
        }

        $weights = [];
        foreach ($section['weights'] as $token => $weight) {
            if (!\is_string($token) || !is_numeric($weight)) {
                return null;
            }
            $weights[$token] = (float) $weight;
        }

        return [
            'bias' => (float) $section['bias'],
            'threshold' => (float) $section['threshold'],
            'weights' => $weights,
        ];
    }
}
