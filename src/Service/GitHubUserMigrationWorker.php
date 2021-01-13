<?php declare(strict_types=1);

namespace App\Service;

use Psr\Log\LoggerInterface;
use Doctrine\Persistence\ManagerRegistry;
use App\Entity\Package;
use App\Entity\User;
use App\Entity\Job;
use Seld\Signal\SignalHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Response;

class GitHubUserMigrationWorker
{
    const HOOK_URL = 'https://packagist.org/api/github';
    const HOOK_URL_ALT = 'https://packagist.org/api/update-package';

    private $logger;
    private $doctrine;
    private $guzzle;
    private $githubWebhookSecret;

    public function __construct(LoggerInterface $logger, ManagerRegistry $doctrine, Client $guzzle, string $githubWebhookSecret)
    {
        $this->logger = $logger;
        $this->doctrine = $doctrine;
        $this->guzzle = $guzzle;
        $this->githubWebhookSecret = $githubWebhookSecret;
    }

    public function process(Job $job, SignalHandler $signal): array
    {
        $em = $this->doctrine->getManager();
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
            $results = ['hooks_setup' => 0, 'hooks_failed' => [], 'hooks_ok_unchanged' => 0];
            foreach ($packageRepository->getGitHubPackagesByMaintainer($id) as $package) {
                $result = $this->setupWebHook($user->getGithubToken(), $package);
                if (is_string($result)) {
                    $results['hooks_failed'][] = ['package' => $package->getName(), 'reason' => $result];
                } elseif ($result === true) {
                    $results['hooks_setup']++;
                } elseif ($result === false) {
                    $results['hooks_ok_unchanged']++;
                }
                // null result means not processed as not a github-like URL
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
            'results' => $results,
        ];
    }

    public function setupWebHook(string $token, Package $package)
    {
        if (!preg_match('#^(?:(?:https?|git)://([^/]+)/|git@([^:]+):)(?P<owner>[^/]+)/(?P<repo>.+?)(?:\.git|/)?$#', $package->getRepository(), $match)) {
            return;
        }

        $this->logger->debug('Updating hooks for package '.$package->getName());

        $repoKey = $match['owner'].'/'.$match['repo'];
        $changed = false;

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
                    return $hook['name'] === 'web' && (strpos($hook['config']['url'], self::HOOK_URL) === 0 || strpos($hook['config']['url'], self::HOOK_URL_ALT) === 0);
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
                    $changed = true;
                } elseif (!$package->isAutoUpdated()) {
                    // if the hook looks correct but package is not marked auto-updated, we do not mark it valid so it gets recreated below
                    continue;
                }

                $hasValidHook = true;
                unset($currentHooks[$index]);
            }

            foreach (array_merge(array_values($currentHooks), $legacyHooks) as $hook) {
                $this->logger->debug('Deleting hook '.$hook['id'], ['hook' => $hook]);
                $this->request($token, 'DELETE', 'repos/'.$repoKey.'/hooks/'.$hook['id']);
                $changed = true;
            }

            if (!$hasValidHook) {
                $this->logger->debug('Creating hook');
                $resp = $this->request($token, 'POST', 'repos/'.$repoKey.'/hooks', $hookData);
                if ($resp->getStatusCode() === 201) {
                    $hooks[] = json_decode((string) $resp->getBody(), true);
                    $changed = true;
                }
            }

            if (count($hooks) && !preg_match('{^https://api\.github\.com/repos/'.$repoKey.'/hooks/}', $hooks[0]['url'])) {
                if (preg_match('{https://api\.github\.com/repos/([^/]+/[^/]+)/hooks}', $hooks[0]['url'], $match)) {
                    $package->setRepository('https://github.com/'.$match[1]);
                    $this->doctrine->getManager()->flush($package);
                }
            }
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            if ($msg = $this->isAcceptableException($e)) {
                $this->logger->debug($msg);

                return $msg;
            }

            $this->logger->error('Rejected GitHub hook request', ['response' => (string) $e->getResponse()->getBody()]);

            throw $e;
        }

        return $changed;
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
        $opts = [
            'headers' => ['Accept' => 'application/vnd.github.v3+json', 'Authorization' => 'token '.$token],
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
                'secret' => $this->githubWebhookSecret,
                'insecure_ssl' => 0,
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
            return 'GitHub user has no admin access to the repository, or Packagist was not granted access to the organization (<a href="https://github.com/settings/connections/applications/a059f127e1c09c04aa5a">check here</a>)';
        }

        if ($e->getCode() === 403 && strpos($e->getMessage(), 'Repository was archived so is read-only') !== false) {
            return 'The repository is archived and read-only';
        }

        return false;
    }
}
