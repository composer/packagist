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

readonly class UserFreezeDisplay extends AbstractAuditLogDisplay
{
    public function __construct(
        private AuditRecordType $type,
        \DateTimeImmutable $datetime,
        public string $username,
        // The freeze-reason enum value (e.g. 'spam'); null for unfreeze records.
        public ?string $reason,
        public ?string $reasonText,
        public ?string $internalReason,
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
        return 'audit_log/display/user_freeze.html.twig';
    }
}
