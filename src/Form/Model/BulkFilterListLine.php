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

namespace App\Form\Model;

/**
 * One parsed line of the bulk filter list entry textarea.
 */
final readonly class BulkFilterListLine
{
    public function __construct(
        /** 1-based index into the textarea as the admin sees it, used in error messages. */
        public int $lineNumber,
        public string $packageName,
        /** Empty when the line carried no constraint, which {@see FilterListEntryBulkRequest} rejects. */
        public string $version,
    ) {
    }
}
