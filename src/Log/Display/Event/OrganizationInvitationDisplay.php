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
use App\Log\Display\OrganizationDisplay;

/**
 * Shared display for the invitation lifecycle (sent/resent/revoked/declined/accepted/expired). Every
 * one of these renders the organization, the invited email (already obfuscated by the factory when the
 * viewer may not see it) and the actor, so a single display carries them all; the concrete type drives
 * the wording via its own template and translation key.
 */
readonly class OrganizationInvitationDisplay extends AbstractLogDisplay
{
    public function __construct(
        private AuditLogEventType $type,
        \DateTimeImmutable $datetime,
        public OrganizationDisplay $organization,
        public string $email,
        ActorDisplay $actor,
        ?string $ip,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): AuditLogEventType
    {
        return $this->type;
    }

    /**
     * Spelled out rather than derived from the type so the template-parity guard in
     * LogDisplayTemplatesTest can see which partials this display owns.
     */
    public function getTemplateName(): string
    {
        return match ($this->type) {
            AuditLogEventType::OrganizationInvitationSent => 'log/display/organization_invitation_sent.html.twig',
            AuditLogEventType::OrganizationInvitationResent => 'log/display/organization_invitation_resent.html.twig',
            AuditLogEventType::OrganizationInvitationRevoked => 'log/display/organization_invitation_revoked.html.twig',
            AuditLogEventType::OrganizationInvitationAccepted => 'log/display/organization_invitation_accepted.html.twig',
            AuditLogEventType::OrganizationInvitationDeclined => 'log/display/organization_invitation_declined.html.twig',
            AuditLogEventType::OrganizationInvitationExpired => 'log/display/organization_invitation_expired.html.twig',
            default => throw new \LogicException($this->type->value.' is not an invitation event'),
        };
    }
}
