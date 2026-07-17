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

namespace App\Organization\Http;

/**
 * Signals that a requested organization slug was freed by a rename and should redirect to the
 * organization's current slug. Turned into a 302 by {@see \App\EventListener\OrganizationRedirectListener}.
 */
final class OrganizationRenamedException extends \RuntimeException
{
    public function __construct(public readonly string $currentSlug)
    {
        parent::__construct(sprintf('Organization slug redirects to "%s".', $currentSlug));
    }
}
