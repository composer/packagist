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
 * Marker for domain exceptions thrown when a value object rejects invalid user input; the message is
 * safe to display to end users. The generic {@see \App\Validator\ValidValueObject} validator turns
 * these into form violations.
 */
interface DomainValidationException extends \Throwable
{
}
