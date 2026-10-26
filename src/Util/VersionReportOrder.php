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

namespace App\Util;

use Composer\Semver\VersionParser;

/**
 * Orders security reports newest version first and, within a version, earliest report first.
 * Reports without a report time go last within their version.
 */
final class VersionReportOrder
{
    /**
     * @template T
     *
     * @param list<T>                          $reports
     * @param callable(T): string              $getVersion    a version or version constraint
     * @param callable(T): ?\DateTimeInterface $getReportedAt
     *
     * @return list<T>
     */
    public static function sort(array $reports, callable $getVersion, callable $getReportedAt): array
    {
        $versionParser = new VersionParser();
        $keys = [];
        foreach ($reports as $index => $report) {
            $keys[$index] = [self::versionKey($versionParser, $getVersion($report)), $getReportedAt($report)];
        }

        uksort($keys, static function (int $a, int $b) use ($keys): int {
            [$versionA, $reportedA] = $keys[$a];
            [$versionB, $reportedB] = $keys[$b];

            $byVersion = self::compareVersionKeys($versionB, $versionA);
            if ($byVersion !== 0) {
                return $byVersion;
            }
            if ($reportedA === null || $reportedB === null) {
                return ($reportedA === null) <=> ($reportedB === null);
            }

            return $reportedA <=> $reportedB;
        });

        return array_map(static fn (int $index) => $reports[$index], array_keys($keys));
    }

    /**
     * @return array{int, string} unparseable versions rank lowest, unbounded ones (e.g. dev branches) highest
     */
    private static function versionKey(VersionParser $versionParser, string $version): array
    {
        try {
            $upperBound = $versionParser->parseConstraints($version)->getUpperBound();
        } catch (\UnexpectedValueException) {
            return [0, ''];
        }

        if ($upperBound->isPositiveInfinity()) {
            return [2, ''];
        }

        return [1, $upperBound->getVersion()];
    }

    /**
     * @param array{int, string} $a
     * @param array{int, string} $b
     */
    private static function compareVersionKeys(array $a, array $b): int
    {
        return $a[0] <=> $b[0] ?: version_compare($a[1], $b[1]);
    }
}
