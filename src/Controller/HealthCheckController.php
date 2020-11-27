<?php

namespace App\Controller;

use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Bundle\FrameworkBundle\Controller\Controller;
use ZendDiagnostics\Check;
use ZendDiagnostics\Runner\Runner;
use App\HealthCheck\RedisHealthCheck;
use App\HealthCheck\MetadataDirCheck;
use Predis\Client;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

class HealthCheckController
{
    private LoggerInterface $logger;
    private Client $redisClient;
    private array $awsMeta;
    private string $dbUrl;

    public function __construct(LoggerInterface $logger, Client $redis, array $awsMetadata, string $dbUrl)
    {
        $this->logger = $logger;
        $this->redisClient = $redis;
        $this->awsMeta = $awsMetadata;
        $this->dbUrl = $dbUrl;
    }

    /**
     * @Route("/_aws_lb_check", name="health_check", methods={"GET"})
     */
    public function healthCheckAction(Request $req)
    {
        if ($req->headers->get('X-Forwarded-For') || 0 !== strpos($req->getClientIp(), '10.')) {
            throw new NotFoundHttpException();
        }

        $runner = new Runner();

        $dbhost = parse_url($this->dbUrl, PHP_URL_HOST);
        $dbname = trim(parse_url($this->dbUrl, PHP_URL_PATH), '/');
        $dbuser = parse_url($this->dbUrl, PHP_URL_USER);
        $dbpass = parse_url($this->dbUrl, PHP_URL_PASS);

        if (isset($this->awsMeta['ec2_node'])) {
            $machineName = $this->awsMeta['ec2_node'].' in '.$this->awsMeta['region'];
        } else {
            $machineName = 'dev';
        }

        $runner->addCheck(new Check\DiskUsage(80, 90, '/'));
        if (($this->awsMeta['has_instance_store'] ?? false) === true) {
            $runner->addCheck(new Check\DiskUsage(80, 90, '/mnt/sdephemeral'));
        }
        $runner->addCheck(new Check\DiskFree(100 * 1024 * 1024, '/tmp'));
        $runner->addCheck(new Check\PDOCheck('mysql:dbname='.$dbname.';host='.$dbhost, $dbuser, $dbpass));
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

    private function logResults($results, $msg)
    {
        $this->logger->critical('Health Check '.$msg, array_map(function ($checker) use ($results) {
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
