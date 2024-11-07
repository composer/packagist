<?php

namespace App\Logger;

use Monolog\LogRecord;
use Monolog\Processor\ProcessorInterface;
use Monolog\ResettableInterface;

class LogIdProcessor implements ProcessorInterface, ResettableInterface
{
    private ?string $reqId = null;
    private ?string $jobId = null;

    public function startRequest(): void
    {
        $this->reqId = bin2hex(random_bytes(6));
    }

    public function startJob(string $id): void
    {
        $this->jobId = $id;
    }

    public function reset(): void
    {
        $this->reqId = null;
        $this->jobId = null;
    }

    public function __invoke(LogRecord $record): LogRecord
    {
        if ($this->jobId !== null) {
            $record->extra['job_id'] = $this->jobId;
        }
        if ($this->reqId !== null) {
            $record->extra['req_id'] = $this->reqId;
        }

        return $record;
    }
}
