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
 * Both logs store their actor the same way, so both factories build it the same way.
 */
abstract class AbstractLogDisplayFactory
{
    /**
     * @param array{id: int|null, username: string}|string|null $actor
     */
    protected function buildActor(array|string|null $actor): ActorDisplay
    {
        if ($actor === null) {
            return new ActorDisplay(null, 'unknown');
        }

        if (\is_string($actor)) {
            return new ActorDisplay(null, $actor);
        }

        return new ActorDisplay($actor['id'], $actor['username']);
    }
}
