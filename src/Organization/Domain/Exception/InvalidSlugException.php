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
 * The slug is reserved (deny-list) or collides with a vendor prefix the user cannot access.
 * Nothing is appended to the event stream.
 */
final class InvalidSlugException extends OrganizationException
{
}
