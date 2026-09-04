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
    /** Five aggregate stats keys, then the single per-IP throttle key. */
    private const INIT_KEYS = 6;
    /** Stats keys incremented once per job. */
    private const KEYS_PER_JOB = 4;
    /** day, month, throttleExpiry — followed by one package id per job. */
    private const SCALAR_ARGS = 3;

    /**
     * @var array<string|int>
     */
    private array $args;

    public function getKeysCount(): int
    {
        if (!$this->args) {
            throw new \LogicException('getKeysCount called before setArguments');
        }

        // args are INIT_KEYS + KEYS_PER_JOB per job, then SCALAR_ARGS plus one package id per job
        $jobs = intdiv(\count($this->args) - self::INIT_KEYS - self::SCALAR_ARGS, self::KEYS_PER_JOB + 1);

        return self::INIT_KEYS + self::KEYS_PER_JOB * $jobs;
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
            local numInitKeys = 6
            local numKeysPerJob = 4
            local throttleKey = KEYS[numInitKeys]
            local jobs = math.floor((#KEYS - numInitKeys) / numKeysPerJob)
            local successful = 0

            for job = 1, jobs do
                -- one hash per IP per window, holding packageId => requests so far in the window
                local requests = tonumber(redis.call("HINCRBY", throttleKey, ARGV[3 + job], 1));
                if requests <= 10 then
                    successful = successful + 1;
                    local base = numInitKeys + (job - 1) * numKeysPerJob;
                    for k = 1, numKeysPerJob do
                        redis.call("INCR", KEYS[base + k]);
                    end
                end
            end

            -- Set the TTL once per key: a fresh key has none, and re-applying it on every request
            -- would keep pushing the jittered expiry further out.
            if jobs > 0 and redis.call("PTTL", throttleKey) < 0 then
                redis.call("PEXPIREAT", throttleKey, tonumber(ARGV[3]));
            end

            if successful > 0 then
                redis.call("INCRBY", KEYS[1], successful);
                redis.call("INCRBY", KEYS[2], successful);
                redis.call("INCRBY", KEYS[3], successful);
                redis.call("HINCRBY", KEYS[4] .. "days", ARGV[1], successful);
                redis.call("HINCRBY", KEYS[4] .. "months", ARGV[2], successful);
                redis.call("HINCRBY", KEYS[5] .. "days", ARGV[1], successful);
                redis.call("HINCRBY", KEYS[5] .. "months", ARGV[2], successful);
            end

            return redis.status_reply("OK");
            LUA;
    }
}
