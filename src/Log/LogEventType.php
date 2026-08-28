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
 * An event type that can be rendered as a log row: {@see \App\Log\AuditLogEventType} for the internal
 * audit log, {@see \App\Log\TransparencyLogEventType} for the public transparency log.
 *
 * A display class is shared by both logs wherever the event carries the same detail in each, so the
 * type it was built with identifies the row.
 *
 * The two enums implement this common type only because each log projects a different subset of
 * events; both render under the same shared 'log.' translation vocabulary (see
 * {@see \App\Log\Display\AbstractLogDisplay::getTypeTranslationKey()}).
 */
interface LogEventType extends \BackedEnum
{
}
