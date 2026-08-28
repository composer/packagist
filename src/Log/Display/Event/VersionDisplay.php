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
use App\Log\TransparencyLogEventType;

/**
 * Version events whose only detail is the version string: created / deleted.
 */
readonly class VersionDisplay extends AbstractLogDisplay
{
    public function __construct(
        private TransparencyLogEventType $type,
        \DateTimeImmutable $datetime,
        public string $packageName,
        public string $version,
        ActorDisplay $actor,
    ) {
        parent::__construct($datetime, $actor);
    }

    public function getType(): TransparencyLogEventType
    {
        return $this->type;
    }

    public function getTemplateName(): string
    {
        return 'log/display/version.html.twig';
    }
}
