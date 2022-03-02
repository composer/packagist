<?php declare(strict_types=1);

namespace App\SecurityAdvisory;

use App\Entity\Package;
use Composer\IO\ConsoleIO;

interface SecurityAdvisorySourceInterface
{
    public function getAdvisories(ConsoleIO $io): ?RemoteSecurityAdvisoryCollection;
}
