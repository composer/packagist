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

namespace App\Audit\Display\Transparency;

use App\Audit\Display\ActorDisplay;
use App\Audit\TransparencyLogType;

readonly class VersionReferenceChangeBlockedDisplay extends AbstractTransparencyDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public string $packageName,
        public string $version,
        public ?string $refFrom,
        public string $refTo,
        ActorDisplay $actor,
    ) {
        parent::__construct($datetime, $actor);
    }

    public function getType(): TransparencyLogType
    {
        return TransparencyLogType::VersionReferenceChangeBlocked;
    }

    public function getTemplateName(): string
    {
        return 'transparency_log/display/version_reference_change_blocked.html.twig';
    }
}
