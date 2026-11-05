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
 * A user account-security event (2FA, password, email, GitHub link) fanned out onto a package the
 * user maintains.
 */
readonly class MaintainerAccountEventDisplay extends AbstractLogDisplay
{
    public function __construct(
        private TransparencyLogEventType $type,
        \DateTimeImmutable $datetime,
        public string $maintainerUsername,
        public ?int $maintainerId,
        public string $packageName,
        ActorDisplay $actor,
        // only set on github_linked_with_user
        public ?string $githubUsername = null,
        public ?int $githubId = null,
    ) {
        parent::__construct($datetime, $actor);
    }

    public function getType(): TransparencyLogEventType
    {
        return $this->type;
    }

    public function getTemplateName(): string
    {
        return 'log/display/account_event.html.twig';
    }
}
