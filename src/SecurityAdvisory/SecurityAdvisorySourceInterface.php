<?php declare(strict_types=1);

namespace App\SecurityAdvisory;

use Composer\IO\ConsoleIO;

interface SecurityAdvisorySourceInterface
{
    /**
     * @return null|RemoteSecurityAdvisory[]
     */
    public function getAdvisories(ConsoleIO $io): ?array;
}
