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

class RemoteSecurityAdvisoryCollection
{
    /** @var array<string, list<RemoteSecurityAdvisory>> */
    private array $groupedSecurityAdvisories = [];

    /**
     * @param list<RemoteSecurityAdvisory> $advisories
     * @param array<string, array<string, true>> $withdrawnAdvisories packageName => (remoteId => true) for advisories withdrawn at the source
     */
    public function __construct(
        array $advisories,
        private readonly array $withdrawnAdvisories = [],
    ) {
        foreach ($advisories as $advisory) {
            $this->groupedSecurityAdvisories[$advisory->packageName][] = $advisory;
        }
    }

    /**
     * @return list<RemoteSecurityAdvisory>
     */
    public function getAdvisoriesForPackageName(string $packageName): array
    {
        return $this->groupedSecurityAdvisories[$packageName] ?? [];
    }

    /**
     * @return list<string>
     */
    public function getPackageNames(): array
    {
        return array_keys($this->groupedSecurityAdvisories);
    }

    /**
     * Whether the advisory with the given remote id was withdrawn at the source for the given package.
     */
    public function isWithdrawn(string $packageName, string $remoteId): bool
    {
        return isset($this->withdrawnAdvisories[$packageName][$remoteId]);
    }

    /**
     * @return list<string> package names that have at least one advisory withdrawn at the source
     */
    public function getWithdrawnPackageNames(): array
    {
        return array_keys($this->withdrawnAdvisories);
    }
}
