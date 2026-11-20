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

use App\Log\AuditLogEventType;
use App\Log\Display\AbstractLogDisplay;
use App\Log\Display\ActorDisplay;

readonly class UserVerifiedDisplay extends AbstractLogDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public string $username,
        public string $email,
        ActorDisplay $actor,
        ?string $ip,
        /** The viewer only sees the address thanks to ROLE_AUDITOR, so the template flags it. */
        public bool $emailAuditorOnly = false,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): AuditLogEventType
    {
        return AuditLogEventType::UserVerified;
    }

    public function getTemplateName(): string
    {
        return 'log/display/user_verified.html.twig';
    }
}
