<?php declare(strict_types = 1);

namespace App\HealthCheck;

use ZendDiagnostics\Check\AbstractCheck;
use ZendDiagnostics\Result\Failure;
use ZendDiagnostics\Result\Success;
use ZendDiagnostics\Result\Warning;

class RedisHealthCheck extends AbstractCheck
{
    /** @var \Predis\Client */
    private $redis;

    public function __construct(\Predis\Client $redis)
    {
        $this->redis = $redis;
    }

    public function check()
    {
        try {
            $info = $this->redis->info();
            $usage = $info['Memory']['used_memory'] / (empty($info['Memory']['maxmemory']) ? 2*1024*1024*1024 : $info['Memory']['maxmemory']); // default maxmemory to 2GB if not available e.g. on dev boxes
            if ($usage > 0.85) {
                return new Warning('Redis free memory is dangerously low, usage is at '.round($usage * 100, 2).'%', $info['Memory']);
            }

            // only warn for fragmented memory when amount of memory is above 256MB
            if ($info['Memory']['used_memory'] > 256*1024*1024 && $info['Memory']['mem_fragmentation_ratio'] > 3) {
                return new Warning('Redis memory fragmentation ratio is pretty high, maybe redis instances should be restarted', $info['Memory']);
            }

            return new Success('Redis stats nominal', [
                'memory usage' => round($usage * 100, 2).'%',
                'fragmentation' => $info['Memory']['mem_fragmentation_ratio']
            ]);
        } catch (\Throwable $e) {
            return new Failure($e->getMessage(), ['exception' => $e]);
        }
    }
}
