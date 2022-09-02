<?php declare(strict_types = 1);

/*
 * This file is part of Packagist.
 *
 * (c) Jordi Boggiano <j.boggiano@seld.be>
 *     Nils Adermann <naderman@naderman.de>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace App\HealthCheck;

use Laminas\Diagnostics\Check\CheckInterface;
use Laminas\Diagnostics\Result\Failure;
use Laminas\Diagnostics\Result\ResultInterface;
use Laminas\Diagnostics\Result\Success;
use Laminas\Diagnostics\Result\Warning;

class RedisHealthCheck implements CheckInterface
{
    private \Predis\Client $redis;

    public function __construct(\Predis\Client $redis)
    {
        $this->redis = $redis;
    }

    public function getLabel(): string
    {
        return 'Check if Redis has enough memory.';
    }

    public function check(): ResultInterface
    {
        try {
            $info = $this->redis->info();
            $usage = $info['Memory']['used_memory'] / (empty($info['Memory']['maxmemory']) ? 2 * 1024 * 1024 * 1024 : $info['Memory']['maxmemory']); // default maxmemory to 2GB if not available e.g. on dev boxes
            if ($usage > 0.85) {
                return new Warning('Redis free memory is dangerously low, usage is at '.round($usage * 100, 2).'%', $info['Memory']);
            }

            // only warn for fragmented memory when amount of memory is above 256MB
            if ($info['Memory']['used_memory'] > 256 * 1024 * 1024 && $info['Memory']['mem_fragmentation_ratio'] > 8) {
                return new Warning('Redis memory fragmentation ratio is pretty high, maybe redis instances should be restarted', $info['Memory']);
            }

            return new Success('Redis stats nominal', [
                'memory usage' => round($usage * 100, 2).'%',
                'fragmentation' => $info['Memory']['mem_fragmentation_ratio'],
            ]);
        } catch (\Throwable $e) {
            return new Failure($e->getMessage(), ['exception' => $e]);
        }
    }
}
