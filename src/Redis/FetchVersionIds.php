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

class FetchVersionIds extends \Predis\Command\ScriptCommand
{
    public function getScript(): string
    {
        return <<<LUA
local results = {};
local resultId = 0;
local pkgName = '';
for i, key in ipairs(ARGV) do
    if i % 2 == 1 then
        pkgName = key
    else
        local id = redis.call("HGET", pkgName, key);
        table.insert(results, id);
    end
end

return results;
LUA;
    }
}
