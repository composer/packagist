<?php

namespace Packagist\Redis;

class DownloadsIncr extends \Predis\Command\ScriptCommand
{
    public function getKeysCount()
    {
        return 7;
    }

    public function getScript()
    {
        return <<<LUA
redis.call("incr", KEYS[1]);
redis.call("incr", KEYS[2]);
redis.call("incr", KEYS[3]);
redis.call("incr", KEYS[4]);
redis.call("incr", KEYS[5]);
redis.call("incr", KEYS[6]);
redis.call("incr", KEYS[7]);
LUA;
    }
}
