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

use App\Audit\AuditRecordType;
use App\Log\Display\AbstractLogDisplay;
use App\Log\Display\ActorDisplay;
use App\Log\Display\OrganizationDisplay;

readonly class OrganizationMemberRemovedDisplay extends AbstractLogDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public OrganizationDisplay $organization,
        public ActorDisplay $member,
        ActorDisplay $actor,
        ?string $ip,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): AuditRecordType
    {
        return AuditRecordType::OrganizationMemberRemoved;
    }

    public function getTemplateName(): string
    {
        return 'log/display/organization_member_removed.html.twig';
    }
}
