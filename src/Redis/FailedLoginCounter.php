<?php declare(strict_types=1);

namespace App\Redis;

class FailedLoginCounter extends \Predis\Command\ScriptCommand
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

        return count($this->args);
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
for i, key in ipairs(KEYS) do
  redis.call("INCR", key)
  redis.call("EXPIRE", key, 604800)
end
LUA;
    }
}
