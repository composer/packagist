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
     */
    public function __construct(array $advisories)
    {
        foreach ($advisories as $advisory) {
            $this->groupedSecurityAdvisories[$advisory->getPackageName()][] = $advisory;
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
}
