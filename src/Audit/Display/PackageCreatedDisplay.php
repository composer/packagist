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

readonly class PackageCreatedDisplay extends AbstractAuditLogDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public string $packageName,
        public string $repository,
        ActorDisplay $actor,
    ) {
        parent::__construct($datetime, $actor);
    }

    public function getType(): AuditRecordType
    {
        return AuditRecordType::PackageCreated;
    }

    public function getTemplateName(): string
    {
        return 'audit_log/display/package_created.html.twig';
    }
}