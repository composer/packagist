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

namespace App\Organization\Domain\Exception;

/**
 * Base type for all expected organization domain errors. The web layer turns these
 * into form errors / flash messages; the message is safe to show to the user.
 */
class OrganizationException extends \RuntimeException
{
}
