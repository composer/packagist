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

namespace App\Controller;

use Laminas\Diagnostics\Result\Collection;
use Laminas\Diagnostics\Result\ResultInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Laminas\Diagnostics\Check;
use Laminas\Diagnostics\Runner\Runner;
use App\HealthCheck\RedisHealthCheck;
use App\HealthCheck\MetadataDirCheck;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HealthCheckController
{
    private LoggerInterface $logger;
    private Client $redisClient;
    /** @var AwsMetadata */
    private array $awsMeta;
    private string $dbUrl;

    /**
     * @phpstan-param AwsMetadata $awsMetadata
     */
    public function __construct(LoggerInterface $logger, Client $redis, array $awsMetadata, string $dbUrl)
    {
        $this->logger = $logger;
        $this->redisClient = $redis;
        $this->awsMeta = $awsMetadata;
        $this->dbUrl = $dbUrl;
    }

    #[Route(path: '/_aws_lb_check', name: 'health_check', methods: ['GET'])]
    public function healthCheckAction(Request $req): Response
    {
        if ($req->headers->get('X-Forwarded-For') || !str_starts_with($req->getClientIp() ?? '', '10.')) {
            throw new NotFoundHttpException();
        }

        $runner = new Runner();

        $dbhost = (string) parse_url($this->dbUrl, PHP_URL_HOST);
        $dbname = trim((string) parse_url($this->dbUrl, PHP_URL_PATH), '/');
        $dbuser = (string) parse_url($this->dbUrl, PHP_URL_USER);
        $dbpass = (string) parse_url($this->dbUrl, PHP_URL_PASS);
        $dbport = (string) parse_url($this->dbUrl, PHP_URL_PORT);
        if ($dbport === '') {
            $dbport = '3306';
        }

        if (isset($this->awsMeta['ec2_node'])) {
            $machineName = $this->awsMeta['ec2_node'].' in '.$this->awsMeta['region'];
        } else {
            $machineName = 'dev';
        }

        $runner->addCheck(new Check\DiskUsage(80, 90, '/'));
        $runner->addCheck(new Check\DiskFree(100 * 1024 * 1024, '/tmp'));
        $runner->addCheck(new Check\PDOCheck('mysql:dbname='.$dbname.';host='.$dbhost.';port='.$dbport, $dbuser, $dbpass));
        $runner->addCheck(new RedisHealthCheck($this->redisClient));
        $runner->addCheck(new MetadataDirCheck($this->awsMeta));

        $results = $runner->run();

        if (!$results->getFailureCount()) {
            if ($results->getWarningCount()) {
                $this->logResults($results, 'Warning on '.$machineName);
            }

            return new Response('OK', 202);
        }

        $this->logResults($results, 'FAILURE on '.$machineName);

        return new Response('ERRORS', 500);
    }

    private function logResults(Collection $results, string $msg): void
    {
        $this->logger->critical('Health Check '.$msg, array_map(static function ($checker) use ($results) {
            /** @var ResultInterface $result */
            $result = $results[$checker];

            return [
                'checker' => get_class($checker),
                'message' => $result->getMessage(),
                'details' => $result->getData(),
                'result' => strtoupper(str_replace('ZendDiagnostics\\Result\\', '', get_class($result))),
            ];
        }, iterator_to_array($results)));
    }
}
