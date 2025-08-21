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

namespace App\SecurityAdvisory;

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

/**
 * Common Vulnerability Scoring System v3
 *
 * - None:     0.0
 * - Low:      0.1 - 3.9
 * - Medium:   4.0 - 6.9
 * - High:     7.0 - 8.9
 * - Critical: 9.0 - 10.0
 *
 * @see https://www.first.org/cvss/specification-document
 */
enum Severity: string
{
    case NONE = 'none';
    case LOW = 'low';
    case MEDIUM = 'medium';
    case HIGH = 'high';
    case CRITICAL = 'critical';

    /**
     * @see https://docs.github.com/en/code-security/security-advisories/working-with-global-security-advisories-from-the-github-advisory-database/about-the-github-advisory-database#about-cvss-levels
     */
    public static function fromGitHub(?string $githubSeverity): ?Severity
    {
        if (!$githubSeverity) {
            return null;
        }

        // GitHub uses moderate instead of medium
        if (strtolower($githubSeverity) === 'moderate') {
            return self::MEDIUM;
        }

        return Severity::tryFrom(strtolower($githubSeverity));
    }
}
