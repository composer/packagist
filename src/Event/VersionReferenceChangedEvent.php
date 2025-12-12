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

namespace App\Event;

use App\Entity\Version;
use Symfony\Contracts\EventDispatcher\Event;

/**
 * @phpstan-import-type VersionArray from Version
 */
class VersionReferenceChangedEvent extends Event
{
    public function __construct(
        private readonly Version $version,
        /** @var VersionArray $originalMetadata */
        private readonly array $originalMetadata,
        private readonly ?string $oldSourceRef,
        private readonly ?string $oldDistRef,
        private readonly ?string $newSourceRef,
        private readonly ?string $newDistRef,
    ) {
    }

    public function getVersion(): Version
    {
        return $this->version;
    }

    /**
     * @return VersionArray
     */
    public function getOriginalMetadata(): array
    {
        return $this->originalMetadata;
    }

    /**
     * @return VersionArray
     */
    public function getNewMetadata(): array
    {
        return $this->version->toV2Array([]);
    }

    public function getOldSourceRef(): ?string
    {
        return $this->oldSourceRef;
    }

    public function getOldDistRef(): ?string
    {
        return $this->oldDistRef;
    }

    public function hasReferenceChanged(): bool
    {
        return $this->oldSourceRef !== $this->newSourceRef
            || $this->oldDistRef !== $this->newDistRef;
    }

    public function hasMetadataChanged(): bool
    {
        $original = $this->getOriginalMetadata();
        $new = $this->getNewMetadata();

        // Remove dist and source keys as we already know these are different
        unset($original['dist'], $original['source']);
        unset($new['dist'], $new['source']);

        // Ignore the time key, since this is always different
        unset($original['time'], $new['time']);

        return !$this->arraysAreEqual($original, $new);
    }

    /**
     * @param array<mixed> $a
     * @param array<mixed> $b
     */
    private function arraysAreEqual(array $a, array $b): bool
    {
        if (count($a) !== count($b)) {
            return false;
        }

        ksort($a);
        ksort($b);

        foreach ($a as $key => $value) {
            if (!array_key_exists($key, $b)) {
                return false;
            }

            if (is_array($value) && is_array($b[$key])) {
                if (!$this->arraysAreEqual($value, $b[$key])) {
                    return false;
                }
                continue;
            }

            if (is_array($value) !== is_array($b[$key])) {
                return false;
            }

            if ($value !== $b[$key]) {
                return false;
            }
        }

        return true;
    }
}
