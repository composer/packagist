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

namespace App\Log;

/**
 * An event type that can be rendered as a log row: {@see AuditLogEventType} for the internal audit
 * log, {@see TransparencyLogEventType} for the public transparency log.
 *
 * Two enums because each log publishes a different set of events. A display class is shared wherever
 * the event shows the same detail in both logs, and both logs use the same 'log.' translation keys
 * ({@see \App\Log\Display\AbstractLogDisplay::getTypeTranslationKey()}).
 */
interface LogEventType extends \BackedEnum
{
}
