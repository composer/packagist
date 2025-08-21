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
