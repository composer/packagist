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

/**
 * Shared by both logs: the transparency log keeps the same source/dist references out of the version
 * metadata blob that the audit log shows, the rest of the blob is not published
 * ({@see \App\Log\TransparencyLogScrubber}).
 */
readonly class VersionCreatedDisplay extends AbstractLogDisplay
{
    public function __construct(
        private LogEventType $type,
        \DateTimeImmutable $datetime,
        public string $packageName,
        public string $version,
        public ?string $sourceReference,
        public ?string $distReference,
        ActorDisplay $actor,
        // audit_log only: package_transparency_log has no IP column
        ?string $ip = null,
        public ?string $distShasum = null,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): LogEventType
    {
        return $this->type;
    }

    public function getTemplateName(): string
    {
        return 'log/display/version_created.html.twig';
    }
}
