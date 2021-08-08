<?php declare(strict_types=1);

namespace App\SecurityAdvisory;

use App\Entity\Package;
use Composer\IO\ConsoleIO;

interface SecurityAdvisorySourceInterface
{
    /**
     * @return null|RemoteSecurityAdvisory[]
     */
    public function getAdvisories(ConsoleIO $io, Package $package): ?array;
}
