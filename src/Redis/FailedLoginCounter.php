<?php declare(strict_types=1);

namespace App\Redis;

class FailedLoginCounter extends \Predis\Command\ScriptCommand
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
for i, key in ipairs(KEYS) do
  redis.call("INCR", key)
  redis.call("EXPIRE", key, 604800)
end
LUA;
    }
}

