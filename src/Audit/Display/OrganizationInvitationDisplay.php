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

namespace App\Audit\Display;

use App\Audit\AuditRecordType;

/**
 * Shared display for the invitation lifecycle (sent/resent/revoked/declined/accepted/expired). Every
 * one of these renders the organization, the invited email (already obfuscated by the factory when the
 * viewer may not see it) and the actor, so a single display carries them all; the concrete type drives
 * the wording via its own template and translation key.
 */
readonly class OrganizationInvitationDisplay extends AbstractAuditLogDisplay
{
    public function __construct(
        private AuditRecordType $type,
        \DateTimeImmutable $datetime,
        public OrganizationDisplay $organization,
        public string $email,
        ActorDisplay $actor,
        ?string $ip,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): AuditRecordType
    {
        return $this->type;
    }

    public function getTemplateName(): string
    {
        return 'audit_log/display/'.$this->type->value.'.html.twig';
    }
}
