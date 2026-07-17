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

readonly class OrganizationMemberJoinedDisplay extends AbstractAuditLogDisplay
{
    /**
     * @param list<string> $teamNames
     */
    public function __construct(
        \DateTimeImmutable $datetime,
        public OrganizationDisplay $organization,
        public array $teamNames,
        public ActorDisplay $member,
        ?string $ip,
    ) {
        // The member joins on their own behalf, so they are also the actor.
        parent::__construct($datetime, $member, $ip);
    }

    public function getType(): AuditRecordType
    {
        return AuditRecordType::OrganizationMemberJoined;
    }

    public function getTemplateName(): string
    {
        return 'audit_log/display/organization_member_joined.html.twig';
    }
}
