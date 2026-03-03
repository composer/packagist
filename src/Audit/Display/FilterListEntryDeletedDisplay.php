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
use App\FilterList\FilterLists;

readonly class FilterListEntryDeletedDisplay extends AbstractAuditLogDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public string $packageName,
        public string $version,
        public FilterLists $list,
        public string $category,
        ActorDisplay $actor,
        ?string $ip,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): AuditRecordType
    {
        return AuditRecordType::FilterListEntryDeleted;
    }

    public function getTemplateName(): string
    {
        return 'audit_log/display/filter_list_entry_deleted.html.twig';
    }
}
