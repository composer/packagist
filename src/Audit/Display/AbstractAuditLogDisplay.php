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

abstract readonly class AbstractAuditLogDisplay implements AuditLogDisplayInterface
{
    public function __construct(
        public \DateTimeImmutable $datetime,
        public ActorDisplay $actor,
        public ?string $ip,
    ) {
    }

    abstract public function getType(): AuditRecordType;

    public function getDateTime(): \DateTimeImmutable
    {
        return $this->datetime;
    }

    public function getActor(): ActorDisplay
    {
        return $this->actor;
    }

    public function getTypeTranslationKey(): string
    {
        return 'audit_log.type.' . $this->getType()->value;
    }

}
