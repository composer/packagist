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

class DownloadsIncr extends \Predis\Command\ScriptCommand
{
    /**
     * @var array<string|int>
     */
    private array $args;

    public function getKeysCount(): int
    {
        if (!$this->args) {
            throw new \LogicException('getKeysCount called before setArguments');
        }

        return count($this->args) - 4 /* ACTUAL ARGS */;
    }

    /**
     * @param array<string|int> $arguments
     */
    public function setArguments(array $arguments): void
    {
        $this->args = $arguments;

        parent::setArguments($arguments);
    }

    public function getScript(): string
    {
        return <<<LUA
            local doIncr = false;
            local successful = 0;
            local numInitKeys = 6
            local numKeysPerJob = 5
            for i, key in ipairs(KEYS) do
                if i < numInitKeys then
                    -- nothing
                elseif ((i - numInitKeys) % numKeysPerJob) == 0 then
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
