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
use App\FilterList\FilterLists;
use App\FilterList\FilterSources;
use App\Log\Display\AbstractLogDisplay;
use App\Log\Display\ActorDisplay;

readonly class FilterListEntryEnabledDisplay extends AbstractLogDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public string $packageName,
        public string $version,
        public FilterLists $list,
        public FilterSources $source,
        public ?string $reason,
        ActorDisplay $actor,
        ?string $ip,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): AuditRecordType
    {
        return AuditRecordType::FilterListEntryEnabled;
    }

    public function getTemplateName(): string
    {
        return 'log/display/filter_list_entry_enabled.html.twig';
    }
}
