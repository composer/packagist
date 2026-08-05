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

readonly class CanonicalUrlChangedDisplay extends AbstractTransparencyDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public string $packageName,
        public ?string $repositoryFrom,
        public ?string $repositoryTo,
        ActorDisplay $actor,
    ) {
        parent::__construct($datetime, $actor);
    }

    public function getType(): TransparencyLogType
    {
        return TransparencyLogType::CanonicalUrlChanged;
    }

    public function getTemplateName(): string
    {
        return 'transparency_log/display/canonical_url_changed.html.twig';
    }
}
