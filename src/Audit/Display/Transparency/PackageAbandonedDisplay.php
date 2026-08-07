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

use App\Audit\AbandonmentReason;
use App\Audit\Display\ActorDisplay;
use App\Audit\TransparencyLogType;

readonly class PackageAbandonedDisplay extends AbstractTransparencyDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public string $packageName,
        public ?string $repository,
        public ?string $replacementPackage,
        public ?string $reason,
        ActorDisplay $actor,
    ) {
        parent::__construct($datetime, $actor);
    }

    /**
     * Translation key for the reason label, or null when the projected value is not a reason we know
     * how to name, so the row shows nothing rather than a raw enum value.
     */
    public function getReasonTranslationKey(): ?string
    {
        if ($this->reason === null || AbandonmentReason::tryFrom($this->reason) === null) {
            return null;
        }

        return 'transparency_log.abandonment_reason.'.$this->reason;
    }

    public function getType(): TransparencyLogType
    {
        return TransparencyLogType::PackageAbandoned;
    }

    public function getTemplateName(): string
    {
        return 'transparency_log/display/package_abandoned.html.twig';
    }
}
