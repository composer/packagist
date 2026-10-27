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

class WordPressRemoteSecurityAdvisoryCollection extends RemoteSecurityAdvisoryCollection
{
    /** @var array<string, list<RemoteSecurityAdvisory>> */
    private array $groupedSecurityAdvisories = [];

    public const WORDPRESS_CORE_PROVIDER = 'wordpress/core-implementation';

    /**
     * List of WordPress Core Implementation provider packages.
     * @see https://packagist.org/providers/wordpress/core-implementation
     */
    private const WORDPRESS_CORE_PACKAGES = [
        'wordpress/core-implementation',
        'roots/wordpress-full',
        'roots/wordpress-no-content',
        'johnpbloch/wordpress-core',
    ];

    /**
     * @return list<RemoteSecurityAdvisory>
     */
    public function getAdvisoriesForPackageName(string $packageName): array
    {
        if (in_array($packageName, self::WORDPRESS_CORE_PACKAGES)) {
            return $this->groupedSecurityAdvisories[self::WORDPRESS_CORE_PROVIDER] ?? [];
        }

        return [];
    }

    /**
     * @return list<string>
     */
    public function getPackageNames(): array
    {
        return self::WORDPRESS_CORE_PACKAGES;
    }

    /**
     * Whether the advisory with the given remote id was withdrawn at the source for the given package.
     */
    public function isWithdrawn(string $packageName, string $remoteId): bool
    {
        if (in_array($packageName, self::WORDPRESS_CORE_PACKAGES)) {
            return isset($this->withdrawnAdvisories[self::WORDPRESS_CORE_PROVIDER][$remoteId]);
        }

        return false;
    }

    /**
     * @return list<string> package names that have at least one advisory withdrawn at the source
     */
    public function getWithdrawnPackageNames(): array
    {
        return empty($this->withdrawnAdvisories[self::WORDPRESS_CORE_PROVIDER]) ? [] : self::WORDPRESS_CORE_PACKAGES;
    }
}
