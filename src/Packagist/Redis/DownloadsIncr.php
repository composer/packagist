<?php

namespace Packagist\Redis;

class DownloadsIncr extends \Predis\Command\ScriptCommand
{
    private $args;

    public function getKeysCount()
    {
        if (!$this->args) {
            throw new \LogicException('getKeysCount called before filterArguments');
        }

        return count($this->args);
    }

    protected function filterArguments(array $arguments)
    {
        $this->args = $arguments;

        return parent::filterArguments($arguments);
    }

    public function getScript()
    {
        return <<<LUA
local doIncr = false;
local successful = 0;
for i, key in ipairs(KEYS) do
    if i == 1 then
        -- nothing
    elseif ((i - 2) % 7) == 0 then
        local requests = redis.call("INCR", key);
        if 1 == requests then
            redis.call("EXPIRE", key, 86400);
        end

        doIncr = false;
        if requests <= 10 then
            doIncr = true;
            successful = successful + 1;
        end
    elseif doIncr then
        redis.call("INCR", key);
    end
end

if successful > 0 then
    redis.call("INCRBY", KEYS[1], successful);
end

return redis.status_reply("OK");
LUA;
    }
}
