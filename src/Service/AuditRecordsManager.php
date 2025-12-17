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

namespace App\Service;

use App\Entity\AuditRecord;
use Symfony\Component\HttpFoundation\RequestStack;

class AuditRecordsManager
{
    public function __construct(
        private readonly RequestStack $requestStack,
    ) {
    }

    public function enrichWithClientIP(AuditRecord $record): void
    {
        $request = $this->requestStack->getMainRequest();

        $ip = $request?->getClientIp();
        $record->setIp($ip);
    }
}
