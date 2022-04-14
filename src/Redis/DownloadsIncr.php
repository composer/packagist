<?php

namespace App\Redis;

class DownloadsIncr extends \Predis\Command\ScriptCommand
{
    /**
     * @var array<string|int>
     */
    private array $args;

    public function getKeysCount(): int
    {
        if (!$this->args) {
            throw new \LogicException('getKeysCount called before filterArguments');
        }

        return count($this->args) - 4;
    }

    /**
     * @param  array<string|int> $arguments
     * @return array<string|int>
     */
    protected function filterArguments(array $arguments): array
    {
        $this->args = $arguments;

        return parent::filterArguments($arguments);
    }

    public function getScript(): string
    {
        return <<<LUA
local doIncr = false;
local successful = 0;
for i, key in ipairs(KEYS) do
    if i <= 5 then
        -- nothing
    elseif ((i - 6) % 6) == 0 then
        local requests = tonumber(redis.call("ZINCRBY", key, 1, ARGV[1]));
        if 1 == requests then
            redis.call("PEXPIREAT", key, tonumber(ARGV[4]));
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
    redis.call("INCRBY", KEYS[2], successful);
    redis.call("INCRBY", KEYS[3], successful);
    redis.call("HINCRBY", KEYS[4] .. "days", ARGV[2], successful);
    redis.call("HINCRBY", KEYS[4] .. "months", ARGV[3], successful);
    redis.call("HINCRBY", KEYS[5] .. "days", ARGV[2], successful);
    redis.call("HINCRBY", KEYS[5] .. "months", ARGV[3], successful);
end

return redis.status_reply("OK");
LUA;
    }
}
