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

namespace App\Redis;

class PackagesExist extends \Predis\Command\ScriptCommand
{
    public function getScript(): string
    {
        return <<<LUA
            local results = {};
            for i, packageName in ipairs(ARGV) do
                local exists = redis.call("SISMEMBER", "set:packages", packageName);
                table.insert(results, exists);
            end

            return results;
            LUA;
    }
}
