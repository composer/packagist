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

namespace App\Package\Git;

/**
 * How commits and their files are fetched, from cheapest to most expensive
 */
enum FetchMode
{
    /** commits with their root tree in one request, then blobs by id */
    case TreeDepth1;
    /** commits, then root trees by id, then blobs by id, for servers capping tree filters at depth 0 like GitHub */
    case TreeDepth0;
    /** a depth 1 clone of each commit, for servers without filter support or that refuse wants by id */
    case Snapshot;
}
