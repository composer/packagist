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

readonly abstract class AbstractUserDisplay extends AbstractAuditLogDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public string $username,
        ActorDisplay $actor,
    ) {
        parent::__construct($datetime, $actor);
    }

    abstract public function getType(): AuditRecordType;

    public function getTemplateName(): string
    {
        return 'audit_log/display/user_action.html.twig';
    }
}
