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

use App\Audit\AbandonmentReason;
use App\Entity\Package;
use Symfony\Contracts\EventDispatcher\Event;

class PackageUnabandonedEvent extends Event
{
    public function __construct(
        private readonly Package $package,
        private readonly AbandonmentReason $reason,
    ) {
    }

    public function getPackage(): Package
    {
        return $this->package;
    }

    public function getReason(): AbandonmentReason
    {
        return $this->reason;
    }
}
