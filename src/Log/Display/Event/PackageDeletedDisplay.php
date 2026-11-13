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

namespace App\Log\Display\Event;

use App\Log\Display\AbstractLogDisplay;
use App\Log\Display\ActorDisplay;
use App\Log\LogEventType;

readonly class PackageDeletedDisplay extends AbstractLogDisplay
{
    public function __construct(
        private LogEventType $type,
        \DateTimeImmutable $datetime,
        public string $packageName,
        public ?string $repository,
        public ?string $reason,
        ActorDisplay $actor,
        // audit_log only: package_transparency_log rows are scrubbed of both at projection time
        ?string $ip = null,
        public ?string $internalReason = null,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): LogEventType
    {
        return $this->type;
    }

    public function getTemplateName(): string
    {
        return 'log/display/package_deleted.html.twig';
    }
}
