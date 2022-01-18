<?php

namespace App\Redis;

class FetchVersionIds extends \Predis\Command\ScriptCommand
{
    public function getKeysCount(): int
    {
        return 0;
    }

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
