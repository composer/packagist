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

use App\Entity\Package;
use App\Entity\SecurityAdvisory;
use App\Entity\User;
use App\Entity\Vendor;
use App\Model\DownloadManager;
use App\Model\ProviderManager;
use App\Model\VersionIdCache;
use App\Service\FallbackGitHubAuthProvider;
use App\Service\GitHubUserMigrationWorker;
use App\Service\Scheduler;
use App\Util\UserAgentParser;
use Composer\Pcre\Preg;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;
use Graze\DogStatsD\Client as StatsDClient;
use Psr\Log\LoggerInterface;
use Symfony\Component\Routing\RouterInterface;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

enum ApiType {
    case Safe;
    case Unsafe;
}

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiController extends Controller
{
    private const REGEXES = [
        'gitlab'         => '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)+)(?:\.git|/)?$}i',
        'any'            => '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)*)(?:\.git|/)?$}i',
        'bitbucket_push' => '{^(?:https?://|git://|git@)?(?:api\.)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(?:\.git)?/?$}i',
        'bitbucket_hook' => '{^(?:https?://|git://|git@)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(?:\.git)?/?$}i',
    ];

    public function __construct(
        private Scheduler $scheduler,
        private LoggerInterface $logger,
        private HttpClientInterface $httpClient,
        private readonly FallbackGitHubAuthProvider $fallbackGitHubAuthProvider,
    ) {
    }

    #[Route(path: '/packages.json', name: 'packages', defaults: ['_format' => 'json'], methods: ['GET'])]
    public function packagesAction(string $webDir): Response
    {
        // fallback if any of the dumped files exist
        $rootJson = $webDir.'/packages_root.json';
        if (file_exists($rootJson)) {
            return new BinaryFileResponse($rootJson);
        }
        $rootJson = $webDir.'/packages.json';
        if (file_exists($rootJson)) {
            return new BinaryFileResponse($rootJson);
        }

        $this->logger->alert('packages.json is missing and the fallback controller is being hit, you need to use bin/console packagist:dump');

        return new Response('Horrible misconfiguration or the dumper script messed up, you need to use bin/console packagist:dump', 404);
    }

    #[Route(path: '/api/create-package', name: 'generic_create', defaults: ['_format' => 'json'], methods: ['POST'])]
    public function createPackageAction(Request $request, ProviderManager $providerManager, GitHubUserMigrationWorker $githubUserMigrationWorker, RouterInterface $router, ValidatorInterface $validator): JsonResponse
    {
        $payload = json_decode((string) $request->request->get('payload'), true);
        if (!$payload && $request->headers->get('Content-Type') === 'application/json') {
            $payload = json_decode($request->getContent(), true);
        }

        if (!$payload || !is_array($payload)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing payload parameter'], 406);
        }
        if (isset($payload['repository']['url']) && is_string($payload['repository']['url'])) { // supported for BC
            $url = $payload['repository']['url'];
        } elseif (isset($payload['repository']) && is_string($payload['repository'])) {
            $url = $payload['repository'];
        } else {
            return new JsonResponse(['status' => 'error', 'message' => '{repository: string} expected in payload'], 406);
        }

        $user = $this->findUser($request);
        if (null === $user) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request'], 406);
        }

        $package = new Package;
        $package->addMaintainer($user);
        $package->setRepository($url);
        $errors = $validator->validate($package, groups: ['Default', 'Create']);
        if (count($errors) > 0) {
            $errorArray = [];
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] = $error->getMessage();
            }

            return new JsonResponse(['status' => 'error', 'message' => $errorArray], 406);
        }
        try {
            $em = $this->getEM();
            $em->getRepository(Vendor::class)->createIfNotExists($package->getVendor());
            $em->persist($package);
            $em->flush();

            $providerManager->insertPackage($package);
            if ($user->getGithubToken()) {
                $githubUserMigrationWorker->setupWebHook($user->getGithubToken(), $package);
            }
        } catch (\Exception $e) {
            $this->logger->critical($e->getMessage(), ['exception', $e]);

            return new JsonResponse(['status' => 'error', 'message' => 'Error saving package'], 500);
        }

        return new JsonResponse(['status' => 'success'], 202);
    }

    #[Route(path: '/api/update-package', name: 'generic_postreceive', defaults: ['_format' => 'json'], methods: ['POST'])]
    #[Route(path: '/api/github', name: 'github_postreceive', defaults: ['_format' => 'json'], methods: ['POST'])]
    #[Route(path: '/api/bitbucket', name: 'bitbucket_postreceive', defaults: ['_format' => 'json'], methods: ['POST'])]
    public function updatePackageAction(Request $request, string $githubWebhookSecret, StatsDClient $statsd): JsonResponse
    {
        // parse the payload
        $payload = json_decode((string) $request->request->get('payload'), true);
        if (!$payload && $request->headers->get('Content-Type') === 'application/json') {
            $payload = json_decode($request->getContent(), true);
        }

        if (!$payload || !is_array($payload)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing payload parameter'], 406);
        }

        if (isset($payload['project']['git_http_url'])) { // gitlab event payload
            $urlRegex = self::REGEXES['gitlab'];
            $url = $payload['project']['git_http_url'];
            $remoteId = null;
        } elseif (isset($payload['repository']) && is_string($payload['repository'])) { // anything hook
            $urlRegex = self::REGEXES['any'];
            $url = $payload['repository'];
            $remoteId = null;
        } elseif (isset($payload['repository']['url']) && is_string($payload['repository']['url'])) { // github hook
            $urlRegex = self::REGEXES['any'];
            $url = $payload['repository']['url'];
            $remoteId = isset($payload['repository']['id']) && (is_string($payload['repository']['id']) || is_int($payload['repository']['id'])) ? $payload['repository']['id'] : null;
        } elseif (isset($payload['repository']['links']['html']['href'])) { // bitbucket push event payload
            $urlRegex = self::REGEXES['bitbucket_push'];
            $url = $payload['repository']['links']['html']['href'];
            $remoteId = null;
        } elseif (isset($payload['canon_url']) && isset($payload['repository']['absolute_url'])) { // bitbucket post hook (deprecated)
            $urlRegex = self::REGEXES['bitbucket_hook'];
            $url = $payload['canon_url'].$payload['repository']['absolute_url'];
            $remoteId = null;
        } else {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid payload'], 406);
        }

        $statsd->increment('update_pkg_api');

        $url = str_replace('https://api.github.com/repos', 'https://github.com', $url);

        return $this->receiveUpdateRequest($request, $url, $urlRegex, $remoteId, $githubWebhookSecret);
    }

    #[Route(path: '/api/packages/{package}', name: 'api_edit_package', requirements: ['package' => '[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?'], defaults: ['_format' => 'json'], methods: ['PUT'])]
    public function editPackageAction(Request $request, Package $package, ValidatorInterface $validator, StatsDClient $statsd): JsonResponse
    {
        $user = $this->findUser($request);
        if (!$user) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request'], 406);
        }
        if (!$package->getMaintainers()->contains($user)) {
            throw new AccessDeniedException;
        }

        $statsd->increment('edit_package_api');

        $payload = json_decode((string) $request->request->get('payload'), true);
        if (!$payload && $request->headers->get('Content-Type') === 'application/json') {
            $payload = json_decode($request->getContent(), true);
        }

        if (!isset($payload['repository']) || !is_string($payload['repository'])) {
            return new JsonResponse(['status' => 'error', 'message' => '{repository: string} expected in request body'], 406);
        }

        $package->setRepository($payload['repository']);

        $errors = $validator->validate($package, null, ["Update"]);
        if (count($errors) > 0) {
            $errorArray = [];
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] = $error->getMessage();
            }

            return new JsonResponse(['status' => 'error', 'message' => $errorArray], 406);
        }

        $package->setCrawledAt(null);

        $em = $this->getEM();
        $em->persist($package);
        $em->flush();

        return new JsonResponse(['status' => 'success'], 200);
    }

    #[Route(path: '/jobs/{id}', name: 'get_job', requirements: ['id' => '[a-f0-9]+'], defaults: ['_format' => 'json'], methods: ['GET'])]
    public function getJobAction(string $id, StatsDClient $statsd): JsonResponse
    {
        $statsd->increment('get_job_api');

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
     * The version must be the normalized one.
     */
    #[Route(path: '/downloads/', name: 'track_download_batch', defaults: ['_format' => 'json'], methods: ['POST'])]
    public function trackDownloadsAction(Request $request, StatsDClient $statsd, string $trustedIpHeader, DownloadManager $downloadManager, VersionIdCache $versionIdCache): JsonResponse
    {
        $contents = json_decode($request->getContent(), true);
        $invalidInputs = static function ($item) {
            return !isset($item['name'], $item['version']);
        };

        if (!is_array($contents) || !isset($contents['downloads']) || !is_array($contents['downloads']) || array_filter($contents['downloads'], $invalidInputs)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid request format, must be a json object containing a downloads key filled with an array of name/version objects'], 200);
        }

        $ip = $request->headers->get('X-'.$trustedIpHeader);
        if (!$ip) {
            $ip = $request->getClientIp();
        }

        $payload = $versionIdCache->augmentDownloadPayloadWithIds($contents['downloads']);

        $jobs = $failed = [];
        foreach ($payload as $package) {
            // support legacy composer v1 normalized default branches
            if ($package['version'] === '9999999-dev' && !isset($package['id'], $package['vid'])) {
                $result = $this->getDefaultPackageAndVersionId($package['name']);
                if ($result) {
                    $package['id'] = $result['id'];
                    $package['vid'] = $result['vid'];
                }
            }

            if (!isset($package['id'], $package['vid'])) {
                $failed[] = $package;
                continue;
            }

            $jobs[$package['id']] = ['id' => $package['id'], 'vid' => $package['vid'], 'minor' => $this->extractMinorVersion($package['version'])];
        }
        $jobs = array_values($jobs);

        if ($jobs) {
            if (!$request->headers->get('User-Agent')) {
                $this->logger->warning('Missing UA for '.$request->getContent().' (from '.$request->getClientIp().')');
                $statsd->increment('installs.missing-ua');

                return new JsonResponse(['status' => 'success'], 201);
            }

            $uaParser = new UserAgentParser($request->headers->get('User-Agent'));
            if ($uaParser->getComposerVersion() && $uaParser->getPhpMinorVersion() && $uaParser->getComposerMajorVersion()) {
                $downloadManager->addDownloads($jobs, $ip ?? '', $uaParser->getPhpMinorVersion(), $uaParser->getPhpMinorPlatformVersion() ?: $uaParser->getPhpMinorVersion());

                $statsd->increment('installs', 1, 1, [
                    'composer_major' => $uaParser->getComposerMajorVersion(),
                    'php_minor' => $uaParser->getPhpMinorVersion(),
                    'platform_php_minor' => $uaParser->getPhpMinorPlatformVersion() ?: 'unknown',
                    'ci' => $uaParser->getCI() ? 'true' : 'false',
                ]);
                $statsd->increment('installs.composer', 1, 1, [
                    'composer' => $uaParser->getComposerVersion(),
                ]);
                $statsd->increment('installs.http', 1, 1, [
                    'http' => $uaParser->getHttpVersion() ?: 'unknown',
                ]);
                $statsd->increment('installs.php_patch', 1, 1, [
                    'php_patch' => $uaParser->getPhpVersion() ?: 'unknown',
                ]);
                $statsd->increment('installs.os', 1, 1, [
                    'os' => $uaParser->getOs() ?: 'unknown',
                    'php_minor' => $uaParser->getPhpMinorVersion(),
                    'ci' => $uaParser->getCI() ? 'true' : 'false',
                ]);
            } elseif (
                // log only if user-agent header is well-formed (it sometimes contains the header name itself in the value)
                !str_starts_with($request->headers->get('User-Agent'), 'User-Agent:')
                // and only if composer version or php minor are missing, if only composer major is invalid it's irrelevant
                || !$uaParser->getComposerVersion() || !$uaParser->getPhpMinorVersion()
            ) {
                $this->logger->warning('Could not parse UA: '.$request->headers->get('User-Agent').' with '.$request->getContent().' from '.$request->getClientIp());
                $statsd->increment('installs.invalid-ua');
            }
        }

        if ($failed) {
            return new JsonResponse(['status' => 'partial', 'message' => 'Packages '.json_encode($failed).' not found'], 200);
        }

        return new JsonResponse(['status' => 'success'], 201);
    }

    private function extractMinorVersion(string $version): string
    {
        return Preg::replace('{^(\d+\.\d+).*}', '$1', $version);
    }

    #[Route(path: '/api/security-advisories/', name: 'api_security_advisories', defaults: ['_format' => 'json'], methods: ['GET', 'POST'])]
    public function securityAdvisoryAction(Request $request, ProviderManager $providerManager, StatsDClient $statsd): JsonResponse
    {
        $packageNames = array_filter((array) $request->get('packages'), static fn ($name) => is_string($name) && $name !== '');
        if ((!$request->query->has('updatedSince') && !$request->get('packages')) || (!$packageNames && $request->get('packages'))) {
            return new JsonResponse(['status' => 'error', 'message' => 'Missing array of package names as the "packages" parameter'], 400);
        }

        $updatedSince = $request->query->getInt('updatedSince', 0);
        if ($updatedSince > time() + 60) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid updatedSince parameter: timestamp is in the future.'], 400);
        }
        if ($updatedSince < 0) {
            return new JsonResponse(['status' => 'error', 'message' => 'Invalid updatedSince parameter: timestamp should not be negative.'], 400);
        }

        $statsd->increment('advisory_api');

        $advisories = $this->getEM()->getRepository(SecurityAdvisory::class)->searchSecurityAdvisories($packageNames, $updatedSince);
        $response = ['advisories' => $advisories];

        // Ensure known packages are returned even if no advisory is present to ensure they do not get retried by composer in lower prio repos
        // Do a max of 1000 packages to prevent abuse
        foreach (array_slice($packageNames, 0, 1000) as $name) {
            if (!isset($response['advisories'][$name]) && $providerManager->packageExists($name)) {
                $response['advisories'][$name] = [];
            }
        }

        foreach ($response['advisories'] as $packageName => $packageAdvisories) {
            $response['advisories'][$packageName] = array_values($packageAdvisories);
        }

        return new JsonResponse($response, 200);
    }

    /**
     * @return array{id: int, vid: int}|false
     */
    protected function getDefaultPackageAndVersionId(string $name): array|false
    {
        /** @var array{id: string, vid: string}|false $result */
        $result = $this->getEM()->getConnection()->fetchAssociative(
            'SELECT p.id, v.id vid
            FROM package p
            LEFT JOIN package_version v ON p.id = v.package_id
            WHERE p.name = ?
            AND v.defaultBranch = true
            LIMIT 1',
            [$name]
        );

        if (false === $result) {
            return false;
        }

        return ['id' => (int) $result['id'], 'vid' => (int) $result['vid']];
    }

    /**
     * Perform the package update
     *
     * @param string $url the repository's URL (deducted from the request)
     * @param value-of<self::REGEXES> $urlRegex the regex used to split the user packages into domain and path
     */
    protected function receiveUpdateRequest(Request $request, string $url, string $urlRegex, string|int|null $remoteId, string $githubWebhookSecret): JsonResponse
    {
        // try to parse the URL first to avoid the DB lookup on malformed requests
        if (!Preg::isMatchStrictGroups($urlRegex, $url, $match)) {
            return new JsonResponse(['status' => 'error', 'message' => 'Could not parse payload repository URL'], 406);
        }

        if ($remoteId) {
            $remoteId = $match['host'].'/'.$remoteId;
        }

        $packages = null;
        $user = null;
        $autoUpdated = Package::AUTO_MANUAL_HOOK;
        $receiveType = 'manual';
        $source = 'unknown';

        // manual hook set up with user API token as secret
        if ($match['host'] === 'github.com' && $request->getContent() && $request->query->has('username') && $request->headers->has('X-Hub-Signature')) {
            $username = $request->query->get('username');
            $sig = $request->headers->get('X-Hub-Signature');
            $user = $this->getEM()->getRepository(User::class)->findOneBy(['usernameCanonical' => $username]);
            if ($sig && $user && $user->isEnabled()) {
                [$algo, $sig] = explode('=', $sig);
                $expected = hash_hmac($algo, $request->getContent(), $user->getApiToken());
                $source = 'manual_github_hook';
                if (hash_equals($expected, $sig)) {
                    $packages = $this->findGitHubPackagesByRepository($match['path'], (string) $remoteId, $source, $user);
                    $autoUpdated = Package::AUTO_GITHUB_HOOK;
                    $receiveType = 'github_user_secret';
                } else {
                    return new JsonResponse(['status' => 'error', 'message' => 'Secret should be the Packagist API Token for the Packagist user "'.$username.'". Signature verification failed.'], 403);
                }
            } else {
                $user = null;
            }
        }

        if (!$user) {
            // find the user
            $user = $this->findUser($request, ApiType::Safe);
            if ($user) {
                $source = 'manual_hook ('.$user->getUsername().' @ '.$request->getPathInfo().')';
            }
        }

        if (!$user && $match['host'] === 'github.com' && $request->getContent()) {
            $sig = $request->headers->get('X-Hub-Signature');
            if ($sig) {
                [$algo, $sig] = explode('=', $sig);
                $expected = hash_hmac($algo, $request->getContent(), $githubWebhookSecret);
                $source = 'github_official_hook';

                if (hash_equals($expected, $sig)) {
                    $packages = $this->findGitHubPackagesByRepository($match['path'], (string) $remoteId, $source);
                    $autoUpdated = Package::AUTO_GITHUB_HOOK;
                    $receiveType = 'github_auto';
                }
            }
        }

        if (!$packages) {
            if (!$user) {
                return new JsonResponse(['status' => 'error', 'message' => 'Missing or invalid username/apiToken in request'], 403);
            }

            // try to find the user package
            $packages = $this->findPackagesByUrl($user, $url, $urlRegex, $remoteId);
        }

        if (!$packages) {
            return new JsonResponse(['status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)'], 404);
        }

        $jobs = [];

        /** @var Package $package */
        foreach ($packages as $package) {
            $package->setAutoUpdated($autoUpdated);

            $job = $this->scheduler->scheduleUpdate($package, $source);
            $jobs[] = $job->getId();
        }

        $this->getEM()->flush();

        return new JsonResponse(['status' => 'success', 'jobs' => $jobs, 'type' => $receiveType], 202);
    }

    /**
     * Find a user by his username and API token
     */
    protected function findUser(Request $request, ApiType $apiType = ApiType::Unsafe): ?User
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

        $user = $this->getEM()->getRepository(User::class)
            ->createQueryBuilder('u')
            ->where('u.usernameCanonical = :username')
            ->andWhere($apiType === ApiType::Safe ? '(u.apiToken = :apiToken OR u.safeApiToken = :apiToken)' : 'u.apiToken = :apiToken')
            ->setParameter('username', $username)
            ->setParameter('apiToken', $apiToken)
            ->setMaxResults(1)
            ->getQuery()
            ->getOneOrNullResult();

        if ($user && !$user->isEnabled()) {
            return null;
        }

        return $user;
    }

    /**
     * Find a user package given by its full URL
     *
     * @param value-of<self::REGEXES> $urlRegex
     * @return list<Package>
     */
    protected function findPackagesByUrl(User $user, string $url, string $urlRegex, string|int|null $remoteId): array
    {
        if (!Preg::isMatch($urlRegex, $url, $matched)) {
            return [];
        }

        if ($matched['host'] === 'packagist.org') {
            $name = Preg::replace('{^packages/}', '', (string) $matched['path']);
            $package = $this->getEM()->getRepository(Package::class)->findOneBy(['name' => $name]);
            if ($package !== null && $package->getMaintainers()->contains($user)) {
                return [$package];
            }
        }

        $packages = [];
        foreach ($user->getPackages() as $package) {
            if (
                $url === 'https://packagist.org/packages/'.$package->getName()
                || (
                    Preg::isMatch($urlRegex, $package->getRepository(), $candidate)
                    && isset($candidate['host'], $candidate['path'])
                    && isset($matched['host'], $matched['path'])
                    && strtolower($candidate['host']) === strtolower($matched['host'])
                    && strtolower($candidate['path']) === strtolower($matched['path'])
                )
            ) {
                $packages[] = $package;
                if (null !== $remoteId && '' !== $remoteId && !$package->getRemoteId()) {
                    $package->setRemoteId((string) $remoteId);
                }
            }
        }

        return $packages;
    }

    /**
     * @param User|null $user If provided it means the request came with a user's API token and not the packagist-configured secret, so we cannot be sure it is a request coming directly from github
     * @return Package[] the packages found
     */
    protected function findGitHubPackagesByRepository(string $path, string $remoteId, string $source, ?User $user = null): array
    {
        $url = 'https://github.com/'.$path;

        $packageRepo = $this->getEM()->getRepository(Package::class);
        $packages = $packageRepo->findBy(['repository' => $url]);
        $updateUrl = false;

        // maybe url changed, look up by remoteId
        if (!$packages && $remoteId !== '') {
            $packages = $packageRepo->findBy(['remoteId' => $remoteId]);
            $updateUrl = true;

            // the remote id was provided by a user, and not by github directly, so we cannot fully trust it and should verify with github that the URL matches that id
            if (\count($packages) > 0 && $user !== null) {
                $fallbackToken = $this->fallbackGitHubAuthProvider->getAuthToken();
                if (null !== $fallbackToken) {
                    $options = ['auth_bearer' => $fallbackToken];
                } else {
                    $options = [];
                }
                $response = $this->httpClient->request('GET', 'https://api.github.com/repos/'.$path, $options);
                if ($response->getStatusCode() === 404) {
                    throw new NotFoundHttpException('Repo does not exist on github, where did this come from?!');
                }
                $data = json_decode((string) $response->getContent(), true);
                if ('github.com/'.$data['id'] !== $remoteId) {
                    throw new BadRequestHttpException('remoteId '.$remoteId.' does not match the repo URL '.$path);
                }
            }
        }

        if ($user) {
            // need to check ownership if a user is provided as we can not trust that the request came from github in this case
            $packages = array_filter($packages, static function ($p) use ($user, $packageRepo) {
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
                $previousUrl = $package->getRepository();
                $package->setRepository($url);
                if ($url !== $previousUrl) {
                    // ensure we do a full update of all versions to update the repo URL
                    $this->scheduler->scheduleUpdate($package, $source, updateEqualRefs: true, forceDump: true);
                }
            }
        }

        return $packages;
    }
}
