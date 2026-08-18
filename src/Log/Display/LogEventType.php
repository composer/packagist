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

namespace App\Log\Display;

/**
 * An event type that can be rendered as a log row: {@see \App\Audit\AuditRecordType} for the internal
 * audit log, {@see \App\Audit\TransparencyLogType} for the public transparency log.
 *
 * A display class is shared by both logs wherever the event carries the same detail in each, so the
 * type it was built with is what says which log's labels to use.
 */
interface LogEventType extends \BackedEnum
{
    /**
     * Translation prefix of the log this type belongs to, e.g. 'audit_log.'. The two logs word the same
     * event differently ('2FA activated' vs 'Maintainer enabled two-factor authentication').
     */
    public function translationPrefix(): string;
}
