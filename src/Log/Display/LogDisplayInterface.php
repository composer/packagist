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
 * One row of a log table, in whichever log rendered it. This is everything
 * templates/log/_table.html.twig consumes, so both logs share one table.
 */
interface LogDisplayInterface
{
    public function getType(): LogEventType;

    public function getDateTime(): \DateTimeImmutable;

    public function getActor(): ActorDisplay;

    public function getTypeTranslationKey(): string;

    public function getTemplateName(): string;
}
