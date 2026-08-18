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

use App\Audit\TransparencyLogType;
use App\Log\Display\AbstractLogDisplay;
use App\Log\Display\ActorDisplay;

/**
 * A user account-security event (2FA, password, email, GitHub link) fanned out onto a package the
 * user maintains. The specific event is conveyed by the type label; the detail names the maintainer.
 */
readonly class MaintainerAccountEventDisplay extends AbstractLogDisplay
{
    public function __construct(
        private TransparencyLogType $type,
        \DateTimeImmutable $datetime,
        public string $maintainerUsername,
        ActorDisplay $actor,
    ) {
        parent::__construct($datetime, $actor);
    }

    public function getType(): TransparencyLogType
    {
        return $this->type;
    }

    public function getTemplateName(): string
    {
        return 'log/display/account_event.html.twig';
    }
}
