<?php declare(strict_types=1);

namespace Packagist\WebBundle\Service;

use Psr\Log\LoggerInterface;
use Symfony\Bridge\Doctrine\RegistryInterface;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Entity\Job;
use Seld\Signal\SignalHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

class GitHubUserMigrationWorker
{
    const HOOK_URL = 'https://packagist.org/api/github';

    private $logger;
    private $doctrine;
    private $guzzle;
    private $webhookSecret;

    public function __construct(LoggerInterface $logger, RegistryInterface $doctrine, Client $guzzle, string $webhookSecret)
    {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->guzzle = $guzzle;
        $this->webhookSecret = $webhookSecret;
    }

    public function process(Job $job, SignalHandler $signal): array
    {
        $em = $this->doctrine->getEntityManager();
        $id = $job->getPayload()['id'];
        $packageRepository = $em->getRepository(Package::class);
        $userRepository = $em->getRepository(User::class);

        /** @var User $user */
        $user = $userRepository->findOneById($id);

        if (!$user) {
            $this->logger->info('User is gone, skipping', ['id' => $id]);

            return ['status' => Job::STATUS_COMPLETED, 'message' => 'User was deleted, skipped'];
        }

        try {
            $hookChanges = 0;
            foreach ($packageRepository->getGitHubPackagesByMaintainer($id) as $package) {
                $hookChanges += $this->setupWebHook($user->getGithubToken(), $package);
            }
        } catch (\GuzzleHttp\Exception\ServerException $e) {
            return [
                'status' => Job::STATUS_RESCHEDULE,
                'message' => 'Got error, rescheduling: '.$e->getMessage(),
                'after' => new \DateTime('+5 minutes'),
            ];
        } catch (\GuzzleHttp\Exception\ConnectException $e) {
            return [
                'status' => Job::STATUS_RESCHEDULE,
                'message' => 'Got error, rescheduling: '.$e->getMessage(),
                'after' => new \DateTime('+5 minutes'),
            ];
        }

        return [
            'status' => Job::STATUS_COMPLETED,
            'message' => 'Hooks updated for user '.$user->getUsername(),
            'hookChanges' => $hookChanges,
        ];
    }

    public function setupWebHook(string $token, Package $package): int
    {
        if (!preg_match('#^(?:(?:https?|git)://([^/]+)/|git@([^:]+):)(?P<owner>[^/]+)/(?P<repo>.+?)(?:\.git|/)?$#', $package->getRepository(), $match)) {
            return 0;
        }

        $this->logger->debug('Updating hooks for package '.$package->getName());

        $repoKey = $match['owner'].'/'.$match['repo'];

        try {
            $hooks = $this->getHooks($token, $repoKey);

            $legacyHooks = array_values(array_filter(
                $hooks,
                function ($hook) {
                    return $hook['name'] === 'packagist' && $hook['active'] === true;
                }
            ));
            $currentHooks = array_values(array_filter(
                $hooks,
                function ($hook) {
                    return $hook['name'] === 'web' && strpos($hook['config']['url'], self::HOOK_URL) === 0;
                }
            ));

            $hookData = $this->getGitHubHookData();
            $hasValidHook = false;
            foreach ($currentHooks as $index => $hook) {
                $expectedConfigWithoutSecret = $hookData['config'];
                $configWithoutSecret = $hook['config'];
                unset($configWithoutSecret['secret'], $expectedConfigWithoutSecret['secret']);

                if ($hook['updated_at'] < '2018-09-04T13:00:00' || $hook['events'] != $hookData['events'] || $configWithoutSecret != $expectedConfigWithoutSecret || !$hook['active']) {
                    $this->logger->debug('Updating hook '.$hook['id']);
                    $this->request($token, 'PATCH', 'repos/'.$repoKey.'/hooks/'.$hook['id'], $hookData);
                    $hasValidHook = true;
                }
                unset($currentHooks[$index]);
            }

            foreach (array_merge(array_values($currentHooks), $legacyHooks) as $hook) {
                $this->logger->debug('Deleting hook '.$hook['id'], ['hook' => $hook]);
                $this->request($token, 'DELETE', 'repos/'.$repoKey.'/hooks/'.$hook['id']);
            }

            if (!$hasValidHook) {
                $this->logger->debug('Creating hook');
                $this->request($token, 'POST', 'repos/'.$repoKey.'/hooks', $hookData);
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            // repo not found probably means the user does not have admin access to it on github
            if ($msg = $this->isAcceptableException($e)) {
                $this->logger->debug($msg);

                return 0;
            }

            throw $e;
        }

        return 1;
    }

    public function deleteWebHook(string $token, Package $package): bool
    {
        if (!preg_match('#^(?:(?:https?|git)://([^/]+)/|git@([^:]+):)(?P<owner>[^/]+)/(?P<repo>.+?)(?:\.git|/)?$#', $package->getRepository(), $match)) {
            return true;
        }

        $this->logger->debug('Deleting hooks for package '.$package->getName());

        $repoKey = $match['owner'].'/'.$match['repo'];

        try {
            $hooks = $this->getHooks($token, $repoKey);

            foreach ($hooks as $hook) {
                if ($hook['name'] === 'web' && strpos($hook['config']['url'], self::HOOK_URL) === 0) {
                    $this->logger->debug('Deleting hook '.$hook['id'], ['hook' => $hook]);
                    $this->request($token, 'DELETE', 'repos/'.$repoKey.'/hooks/'.$hook['id']);
                }
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($msg = $this->isAcceptableException($e)) {
                $this->logger->debug($msg);

                return false;
            }

            throw $e;
        }

        return true;
    }

    private function getHooks(string $token, string $repoKey): array
    {
        $hooks = [];
        $page = '';

        do {
            $resp = $this->request($token, 'GET', 'repos/'.$repoKey.'/hooks'.$page);
            $hooks = array_merge($hooks, json_decode((string) $resp->getBody(), true));
            $hasNext = false;
            foreach ($resp->getHeader('Link') as $header) {
                if (preg_match('{<https://api.github.com/resource?page=(?P<page>\d+)>; rel="next"}', $header, $match)) {
                    $hasNext = true;
                    $page = '?page='.$match['page'];
                }
            }
        } while ($hasNext);

        return $hooks;
    }

    private function request(string $token, string $method, string $url, array $json = null): Response
    {
        if (strpos($url, '?')) {
            $url .= '&access_token='.$token;
        } else {
            $url .= '?access_token='.$token;
        }

        $opts = [
            'headers' => ['Accept' => 'application/vnd.github.v3+json'],
        ];

        if ($json) {
            $opts['json'] = $json;
        }

        return $this->guzzle->request($method, 'https://api.github.com/' . $url, $opts);
    }

    private function getGitHubHookData(): array
    {
        return [
            'name' => 'web',
            'config' => [
                'url' => self::HOOK_URL,
                'content_type' => 'json',
                'secret' => $this->webhookSecret,
            ],
            'events' => [
                'push',
            ],
            'active' => true,
        ];
    }

    private function isAcceptableException(\Throwable $e)
    {
        // repo not found probably means the user does not have admin access to it on github
        if ($e->getCode() === 404) {
            return 'User has no access, skipping';
        }

        if ($e->getCode() === 403 && strpos($e->getMessage(), 'Repository was archived so is read-only') !== false) {
            return 'Repository was archived';
        }

        return false;
    }
}
