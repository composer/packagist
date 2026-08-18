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

readonly class FilterListEntryEditedDisplay extends AbstractLogDisplay
{
    public function __construct(
        \DateTimeImmutable $datetime,
        public string $packageName,
        public string $version,
        public string $previousVersion,
        public ?string $reason,
        public ?string $previousReason,
        public ?string $link,
        public ?string $previousLink,
        public ?string $internalNote,
        public ?string $previousInternalNote,
        public FilterLists $list,
        public FilterLists $previousList,
        public FilterSources $source,
        ActorDisplay $actor,
        ?string $ip,
    ) {
        parent::__construct($datetime, $actor, $ip);
    }

    public function getType(): AuditRecordType
    {
        return AuditRecordType::FilterListEntryEdited;
    }

    public function getTemplateName(): string
    {
        return 'log/display/filter_list_entry_edited.html.twig';
    }
}
