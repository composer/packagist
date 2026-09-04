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

use App\Audit\AuditRecordType;

interface LogDisplayInterface
{
    public function getType(): AuditRecordType;

    public function getDateTime(): \DateTimeImmutable;

    public function getActor(): ActorDisplay;

    public function getTypeTranslationKey(): string;

    public function getTemplateName(): string;
}
