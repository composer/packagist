<?php

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

use App\Entity\Package;
use App\Entity\SecurityAdvisory;
use App\Entity\User;
use App\Model\ProviderManager;
use App\Service\GitHubUserMigrationWorker;
use App\Service\Scheduler;
use App\Util\UserAgentParser;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Annotation\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Graze\DogStatsD\Client as StatsDClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiController extends Controller
{
    private Scheduler $scheduler;
    private LoggerInterface $logger;

    public function __construct(Scheduler $scheduler, LoggerInterface $logger)
    {
        $this->scheduler = $scheduler;
        $this->logger = $logger;
    }

    /**
     * @Route("/packages.json", name="packages", defaults={"_format" = "json"}, methods={"GET"})
     */
    public function packagesAction(string $webDir)
    {
        // fallback if any of the dumped files exist
        $rootJson = $webDir.'/packages_root.json';
        if (file_exists($rootJson)) {
            return new Response(file_get_contents($rootJson));
        }
        $rootJson = $webDir.'/packages.json';
        if (file_exists($rootJson)) {
            return new Response(file_get_contents($rootJson));
        }

        $this->logger->alert('packages.json is missing and the fallback controller is being hit, you need to use app/console packagist:dump');

        return new Response('Horrible misconfiguration or the dumper script messed up, you need to use app/console packagist:dump', 404);
    }

    /**
     * @Route("/api/create-package", name="generic_create", defaults={"_format" = "json"}, methods={"POST"})
     */
    public function createPackageAction(Request $request, ProviderManager $providerManager, GitHubUserMigrationWorker $githubUserMigrationWorker, RouterInterface $router, ValidatorInterface $validator)
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Missing payload parameter'), 406);
        }
        $url = $payload['repository']['url'];
        $package = new Package;
        $package->setEntityRepository($this->doctrine->getRepository(Package::class));
        $package->setRouter($router);
        $user = $this->findUser($request);
        $package->addMaintainer($user);
        $package->setRepository($url);
        $errors = $validator->validate($package);
        if (count($errors) > 0) {
            $errorArray = array();
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(array('status' => 'error', 'message' => $errorArray), 406);
        }
        try {
            $em = $this->doctrine->getManager();
            $em->persist($package);
            $em->flush();

            $providerManager->insertPackage($package);
            if ($user->getGithubToken()) {
                $githubUserMigrationWorker->setupWebHook($user->getGithubToken(), $package);
            }
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage(), array('exception', $e));
            return new JsonResponse(array('status' => 'error', 'message' => 'Error saving package'), 500);
        }

        return new JsonResponse(array('status' => 'success'), 202);
    }

    /**
     * @Route("/api/update-package", name="generic_postreceive", defaults={"_format" = "json"}, methods={"POST"})
     * @Route("/api/github", name="github_postreceive", defaults={"_format" = "json"}, methods={"POST"})
     * @Route("/api/bitbucket", name="bitbucket_postreceive", defaults={"_format" = "json"}, methods={"POST"})
     */
    public function updatePackageAction(Request $request, string $githubWebhookSecret)
    {
        // parse the payload
        $payload = json_decode($request->request->get('payload'), true);
        if (!$payload && $request->headers->get('Content-Type') === 'application/json') {
            $payload = json_decode($request->getContent(), true);
        }

        if (!$payload) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Missing payload parameter'), 406);
        }

        if (isset($payload['project']['git_http_url'])) { // gitlab event payload
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i';
            $url = $payload['project']['git_http_url'];
            $remoteId = null;
        } elseif (isset($payload['repository']['url'])) { // github/anything hook
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)*)(?:\.git|/)?$}i';
            $url = $payload['repository']['url'];
            $url = str_replace('https://api.github.com/repos', 'https://github.com', $url);
            $remoteId = $payload['repository']['id'] ?? null;
        } elseif (isset($payload['repository']['links']['html']['href'])) { // bitbucket push event payload
            $urlRegex = '{^(?:https?://|git://|git@)?(?:api\.)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['repository']['links']['html']['href'];
            $remoteId = null;
        } elseif (isset($payload['canon_url']) && isset($payload['repository']['absolute_url'])) { // bitbucket post hook (deprecated)
            $urlRegex = '{^(?:https?://|git://|git@)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['canon_url'].$payload['repository']['absolute_url'];
            $remoteId = null;
        } else {
            return new JsonResponse(array('status' => 'error', 'message' => 'Missing or invalid payload'), 406);
        }

        return $this->receivePost($request, $url, $urlRegex, $remoteId, $githubWebhookSecret);
    }

    /**
     * @Route(
     *     "/api/packages/{package}",
     *     name="api_edit_package",
     *     requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"},
     *     defaults={"_format" = "json"},
     *     methods={"PUT"}
     * )
     * @ParamConverter("package", options={"mapping": {"package": "name"}})
     */
    public function editPackageAction(Request $request, Package $package, ValidatorInterface $validator)
    {
        $user = $this->findUser($request);
        if (!$package->getMaintainers()->contains($user) && !$this->isGranted('ROLE_EDIT_PACKAGES')) {
            throw new AccessDeniedException;
        }

        $payload = json_decode($request->request->get('payload'), true);
        if (!$payload && $request->headers->get('Content-Type') === 'application/json') {
            $payload = json_decode($request->getContent(), true);
        }

        $package->setRepository($payload['repository']);

        $errors = $validator->validate($package, null, array("Update"));
        if (count($errors) > 0) {
            $errorArray = array();
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(array('status' => 'error', 'message' => $errorArray), 406);
        }

        $package->setCrawledAt(null);

        $em = $this->doctrine->getManager();
        $em->persist($package);
        $em->flush();

        return new JsonResponse(array('status' => 'success'), 200);
    }

    /**
     * @Route("/downloads/{name}", name="track_download", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"}, methods={"POST"})
     */
    public function trackDownloadAction(Request $request, $name)
    {
        $result = $this->getPackageAndVersionId($name, $request->request->get('version_normalized'));

        if (!$result) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Package not found'), 200);
        }

        $this->downloadManager->addDownloads([['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $request->getClientIp()]]);

        return new JsonResponse(array('status' => 'success'), 201);
    }

    /**
     * @Route("/jobs/{id}", name="get_job", requirements={"id"="[a-f0-9]+"}, defaults={"_format" = "json"}, methods={"GET"})
     */
    public function getJobAction(string $id)
    {
        return new JsonResponse($this->scheduler->getJobStatus($id), 200);
    }

    /**
     * Expects a json like:
     *
     * {
     *     "downloads": [
     *         {"name": "foo/bar", "version": "1.0.0.0"},
     *         // ...
     *     ]
     * }
     *
     * The version must be the normalized one
     *
     * @Route("/downloads/", name="track_download_batch", defaults={"_format" = "json"}, methods={"POST"})
     */
    public function trackDownloadsAction(Request $request, StatsDClient $statsd, string $trustedIpHeader)
    {
        $contents = json_decode($request->getContent(), true);
        if (empty($contents['downloads']) || !is_array($contents['downloads'])) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Invalid request format, must be a json object containing a downloads key filled with an array of name/version objects'), 200);
        }

        $failed = array();

        $ip = $request->headers->get('X-'.$trustedIpHeader);
        if (!$ip) {
            $ip = $request->getClientIp();
        }

        $jobs = [];
        foreach ($contents['downloads'] as $package) {
            $result = $this->getPackageAndVersionId($package['name'], $package['version']);

            if (!$result) {
                $failed[] = $package;
                continue;
            }

            $jobs[$result['id']] = ['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $ip];
        }
        $jobs = array_values($jobs);

        if ($jobs) {
            if (!$request->headers->get('User-Agent')) {
                $this->logger->error('Missing UA for '.$request->getContent().' (from '.$request->getClientIp().')');

                return new JsonResponse(array('status' => 'success'), 201);
            }

            $this->downloadManager->addDownloads($jobs);

            $uaParser = new UserAgentParser($request->headers->get('User-Agent'));
            if (!$uaParser->getComposerVersion()) {
                $this->logger->error('Could not parse UA: '.$_SERVER['HTTP_USER_AGENT'].' with '.$request->getContent().' from '.$request->getClientIp());
            } else {
                $statsd->increment('installs', 1, 1, [
                    'composer' => $uaParser->getComposerVersion() ?: 'unknown',
                    'composer_major' => $uaParser->getComposerMajorVersion() ?: 'unknown',
                    'php_minor' => $uaParser->getPhpMinorVersion() ?: 'unknown',
                    'php_patch' => $uaParser->getPhpVersion() ?: 'unknown',
                    'http' => $uaParser->getHttpVersion() ?: 'unknown',
                    'ci' => $uaParser->getCI() ? 'true' : 'false',
                ]);
            }
        }

        if ($failed) {
            return new JsonResponse(array('status' => 'partial', 'message' => 'Packages '.json_encode($failed).' not found'), 200);
        }

        return new JsonResponse(array('status' => 'success'), 201);
    }

    /**
     * @Route(
     *     "/api/security-advisories/",
     *     name="api_security_adivosries",
     *     defaults={"_format" = "json"},
     *     methods={"GET", "POST"}
     * )
     */
    public function securityAdvisoryAction(Request $request): JsonResponse
    {
        $packageNames = array_filter((array) $request->get('packages'));
        if ((!$request->query->has('updatedSince') && !$request->get('packages')) || (!$packageNames && $request->get('packages'))) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing array of package names as the "packages" parameter'], 400);
        }

        $updatedSince = $request->query->getInt('updatedSince', 0);

        /** @var array[] $advisories */
        $advisories = $this->doctrine->getRepository(SecurityAdvisory::class)->searchSecurityAdvisories($packageNames, $updatedSince);

        $response = ['advisories' => []];
        foreach ($advisories as $advisory) {
            $response['advisories'][$advisory['packageName']][] = $advisory;
        }

        return new JsonResponse($response, 200);
    }

    /**
     * @param string $name
     * @param string $version
     * @return array
     */
    protected function getPackageAndVersionId($name, $version)
    {
        // support legacy composer v1 normalized default branches
        if ($version === '9999999-dev') {
            return $this->doctrine->getManager()->getConnection()->fetchAssoc(
                'SELECT p.id, v.id vid
                FROM package p
                LEFT JOIN package_version v ON p.id = v.package_id
                WHERE p.name = ?
                AND v.defaultBranch = true
                LIMIT 1',
                array($name)
            );
        }

        return $this->doctrine->getManager()->getConnection()->fetchAssoc(
            'SELECT p.id, v.id vid
            FROM package p
            LEFT JOIN package_version v ON p.id = v.package_id
            WHERE p.name = ?
            AND v.normalizedVersion = ?
            LIMIT 1',
            array($name, $version)
        );
    }

    /**
     * Perform the package update
     *
     * @param Request $request the current request
     * @param string $url the repository's URL (deducted from the request)
     * @param string $urlRegex the regex used to split the user packages into domain and path
     * @return Response
     */
    protected function receivePost(Request $request, $url, $urlRegex, $remoteId, string $githubWebhookSecret)
    {
        // try to parse the URL first to avoid the DB lookup on malformed requests
        if (!preg_match($urlRegex, $url, $match)) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Could not parse payload repository URL')), 406);
        }

        if ($remoteId) {
            $remoteId = $match['host'].'/'.$remoteId;
        }

        $packages = null;
        $user = null;
        $autoUpdated = Package::AUTO_MANUAL_HOOK;
        $receiveType = 'manual';

        // manual hook set up with user API token as secret
        if ($match['host'] === 'github.com' && $request->getContent() && $request->query->has('username') && $request->headers->has('X-Hub-Signature')) {
            $username = $request->query->get('username');
            $sig = $request->headers->get('X-Hub-Signature');
            $user = $this->doctrine->getRepository(User::class)->findOneByUsername($username);
            if ($sig && $user && $user->isEnabled()) {
                list($algo, $sig) = explode('=', $sig);
                $expected = hash_hmac($algo, $request->getContent(), $user->getApiToken());
                if (hash_equals($expected, $sig)) {
                    $packages = $this->findPackagesByRepository('https://github.com/'.$match['path'], $remoteId, $user);
                    $autoUpdated = Package::AUTO_GITHUB_HOOK;
                    $receiveType = 'github_user_secret';
                } else {
                    return new Response(json_encode(array('status' => 'error', 'message' => 'Secret should be the Packagist API Token for the Packagist user "'.$username.'". Signature verification failed.')), 403);
                }
            } else {
                $user = null;
            }
        }

        if (!$user) {
            // find the user
            $user = $this->findUser($request);
        }

        if (!$user && $match['host'] === 'github.com' && $request->getContent()) {
            $sig = $request->headers->get('X-Hub-Signature');
            if ($sig) {
                list($algo, $sig) = explode('=', $sig);
                $expected = hash_hmac($algo, $request->getContent(), $githubWebhookSecret);
                if (hash_equals($expected, $sig)) {
                    $packages = $this->findPackagesByRepository('https://github.com/'.$match['path'], $remoteId);
                    $autoUpdated = Package::AUTO_GITHUB_HOOK;
                    $receiveType = 'github_auto';
                }
            }
        }

        if (!$packages) {
            if (!$user) {
                return new Response(json_encode(array('status' => 'error', 'message' => 'Invalid credentials')), 403);
            }

            // try to find the user package
            $packages = $this->findPackagesByUrl($user, $url, $urlRegex, $remoteId);
        }

        if (!$packages) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)')), 404);
        }

        $jobs = [];

        /** @var Package $package */
        foreach ($packages as $package) {
            $package->setAutoUpdated($autoUpdated);

            $job = $this->scheduler->scheduleUpdate($package);
            $jobs[] = $job->getId();
        }

        $this->doctrine->getManager()->flush();

        return new JsonResponse(['status' => 'success', 'jobs' => $jobs, 'type' => $receiveType], 202);
    }

    /**
     * Find a user by his username and API token
     *
     * @param Request $request
     * @return User|null the found user or null otherwise
     */
    protected function findUser(Request $request)
    {
        $username = $request->request->has('username') ?
            $request->request->get('username') :
            $request->query->get('username');

        $apiToken = $request->request->has('apiToken') ?
            $request->request->get('apiToken') :
            $request->query->get('apiToken');

        if (!$apiToken || !$username) {
            return null;
        }

        $user = $this->doctrine->getRepository(User::class)
            ->findOneBy(array('username' => $username, 'apiToken' => $apiToken));

        if ($user && !$user->isEnabled()) {
            return null;
        }

        return $user;
    }

    /**
     * Find a user package given by its full URL
     *
     * @param User $user
     * @param string $url
     * @param string $urlRegex
     * @return array the packages found
     */
    protected function findPackagesByUrl(User $user, $url, $urlRegex, $remoteId)
    {
        if (!preg_match($urlRegex, $url, $matched)) {
            return array();
        }

        $packages = array();
        foreach ($user->getPackages() as $package) {
            if (
                $url === 'https://packagist.org/packages/'.$package->getName()
                || (
                    preg_match($urlRegex, $package->getRepository(), $candidate)
                    && strtolower($candidate['host']) === strtolower($matched['host'])
                    && strtolower($candidate['path']) === strtolower($matched['path'])
                )
            ) {
                $packages[] = $package;
                if ($remoteId && !$package->getRemoteId()) {
                    $package->setRemoteId($remoteId);
                }
            }
        }

        return $packages;
    }

    /**
     * @param string $url
     * @return array the packages found
     */
    protected function findPackagesByRepository(string $url, $remoteId, User $user = null): array
    {
        $packageRepo = $this->doctrine->getRepository(Package::class);
        $packages = $packageRepo->findBy(['repository' => $url]);
        $updateUrl = false;

        // maybe url changed, look up by remoteId
        if (!$packages) {
            $packages = $packageRepo->findBy(['remoteId' => $remoteId]);
            $updateUrl = true;
        }

        if ($user) {
            // need to check ownership if a user is provided as we can not trust that the request came from github in this case
            $packages = array_filter($packages, function ($p) use ($user, $packageRepo) {
                return $packageRepo->isPackageMaintainedBy($p, $user->getId());
            });
        }

        foreach ($packages as $package) {
            if ($remoteId && !$package->getRemoteId()) {
                $package->setRemoteId($remoteId);
            }
        }

        if ($updateUrl) {
            foreach ($packages as $package) {
                $package->setRepository($url);
            }
        }

        return $packages;
    }
}
