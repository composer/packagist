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

namespace Packagist\WebBundle\Controller;

use Composer\Console\HtmlOutputFormatter;
use Composer\Factory;
use Composer\IO\BufferIO;
use Composer\Package\Loader\ArrayLoader;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Repository\InvalidRepositoryException;
use Composer\Repository\VcsRepository;
use Packagist\WebBundle\Entity\Package;
use Packagist\WebBundle\Entity\User;
use Packagist\WebBundle\Entity\Job;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\ParamConverter;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\NotFoundHttpException;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Security\Core\Exception\AccessDeniedException;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiController extends Controller
{
    /**
     * @Route("/packages.json", name="packages", defaults={"_format" = "json"})
     * @Method({"GET"})
     */
    public function packagesAction()
    {
        // fallback if any of the dumped files exist
        $rootJson = $this->container->getParameter('kernel.root_dir').'/../web/packages_root.json';
        if (file_exists($rootJson)) {
            return new Response(file_get_contents($rootJson));
        }
        $rootJson = $this->container->getParameter('kernel.root_dir').'/../web/packages.json';
        if (file_exists($rootJson)) {
            return new Response(file_get_contents($rootJson));
        }

        $this->get('logger')->alert('packages.json is missing and the fallback controller is being hit, you need to use app/console packagist:dump');

        return new Response('Horrible misconfiguration or the dumper script messed up, you need to use app/console packagist:dump', 404);
    }

    /**
     * @Route("/api/create-package", name="generic_create", defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function createPackageAction(Request $request)
    {
        $payload = json_decode($request->getContent(), true);
        if (!$payload) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Missing payload parameter'), 406);
        }
        $url = $payload['repository']['url'];
        $package = new Package;
        $package->setEntityRepository($this->getDoctrine()->getRepository('PackagistWebBundle:Package'));
        $package->setRouter($this->get('router'));
        $user = $this->findUser($request);
        $package->addMaintainer($user);
        $package->setRepository($url);
        $errors = $this->get('validator')->validate($package);
        if (count($errors) > 0) {
            $errorArray = array();
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(array('status' => 'error', 'message' => $errorArray), 406);
        }
        try {
            $em = $this->getDoctrine()->getManager();
            $em->persist($package);
            $em->flush();
        } catch (\Exception $e) {
            $this->get('logger')->critical($e->getMessage(), array('exception', $e));
            return new JsonResponse(array('status' => 'error', 'message' => 'Error saving package'), 500);
        }

        return new JsonResponse(array('status' => 'success'), 202);
    }

    /**
     * @Route("/api/update-package", name="generic_postreceive", defaults={"_format" = "json"})
     * @Route("/api/github", name="github_postreceive", defaults={"_format" = "json"})
     * @Route("/api/bitbucket", name="bitbucket_postreceive", defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function updatePackageAction(Request $request)
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
        } elseif (isset($payload['repository']['url'])) { // github/anything hook
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+(?:/[\w.-]+?)*)(?:\.git|/)?$}i';
            $url = $payload['repository']['url'];
            $url = str_replace('https://api.github.com/repos', 'https://github.com', $url);
        } elseif (isset($payload['repository']['links']['html']['href'])) { // bitbucket push event payload
            $urlRegex = '{^(?:https?://|git://|git@)?(?:api\.)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['repository']['links']['html']['href'];
        } elseif (isset($payload['canon_url']) && isset($payload['repository']['absolute_url'])) { // bitbucket post hook (deprecated)
            $urlRegex = '{^(?:https?://|git://|git@)?(?P<host>bitbucket\.org)[/:](?P<path>[\w.-]+/[\w.-]+?)(\.git)?/?$}i';
            $url = $payload['canon_url'].$payload['repository']['absolute_url'];
        } else {
            return new JsonResponse(array('status' => 'error', 'message' => 'Missing or invalid payload'), 406);
        }

        return $this->receivePost($request, $url, $urlRegex);
    }

    /**
     * @Route(
     *     "/api/packages/{package}",
     *     name="api_edit_package",
     *     requirements={"package"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+?"},
     *     defaults={"_format" = "json"}
     * )
     * @ParamConverter("package", options={"mapping": {"package": "name"}})
     * @Method({"PUT"})
     */
    public function editPackageAction(Request $request, Package $package)
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
        $errors = $this->get('validator')->validate($package, array("Update"));
        if (count($errors) > 0) {
            $errorArray = array();
            foreach ($errors as $error) {
                $errorArray[$error->getPropertyPath()] =  $error->getMessage();
            }
            return new JsonResponse(array('status' => 'error', 'message' => $errorArray), 406);
        }

        $package->setCrawledAt(null);

        $em = $this->getDoctrine()->getManager();
        $em->persist($package);
        $em->flush();

        return new JsonResponse(array('status' => 'success'), 200);
    }

    /**
     * @Route("/downloads/{name}", name="track_download", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function trackDownloadAction(Request $request, $name)
    {
        $result = $this->getPackageAndVersionId($name, $request->request->get('version_normalized'));

        if (!$result) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Package not found'), 200);
        }

        $this->get('packagist.download_manager')->addDownloads([['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $request->getClientIp()]]);

        return new JsonResponse(array('status' => 'success'), 201);
    }

    /**
     * @Route("/jobs/{id}", name="get_job", requirements={"id"="[a-f0-9]+"}, defaults={"_format" = "json"})
     * @Method({"GET"})
     */
    public function getJobAction(Request $request, string $id)
    {
        return new JsonResponse($this->get('scheduler')->getJobStatus($id), 200);
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
     * @Route("/downloads/", name="track_download_batch", defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function trackDownloadsAction(Request $request)
    {
        $contents = json_decode($request->getContent(), true);
        if (empty($contents['downloads']) || !is_array($contents['downloads'])) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Invalid request format, must be a json object containing a downloads key filled with an array of name/version objects'), 200);
        }

        $failed = array();

        $ip = $request->headers->get('X-'.$this->container->getParameter('trusted_ip_header'));
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

            $jobs[] = ['id' => $result['id'], 'vid' => $result['vid'], 'ip' => $ip];
        }

        if ($jobs) {
            $this->get('packagist.download_manager')->addDownloads($jobs);
        }

        if ($failed) {
            return new JsonResponse(array('status' => 'partial', 'message' => 'Packages '.json_encode($failed).' not found'), 200);
        }

        return new JsonResponse(array('status' => 'success'), 201);
    }

    /**
     * @param string $name
     * @param string $version
     * @return array
     */
    protected function getPackageAndVersionId($name, $version)
    {
        return $this->get('doctrine.dbal.default_connection')->fetchAssoc(
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
    protected function receivePost(Request $request, $url, $urlRegex)
    {
        // try to parse the URL first to avoid the DB lookup on malformed requests
        if (!preg_match($urlRegex, $url, $match)) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Could not parse payload repository URL')), 406);
        }

        // find the user
        $user = $this->findUser($request);

        $packages = null;
        $autoUpdated = Package::AUTO_MANUAL_HOOK;
        if (!$user && $match['host'] === 'github.com' && $request->getContent()) {
            $sig = $request->headers->get('X-Hub-Signature');
            if ($sig) {
                list($algo, $sig) = explode('=', $sig);
                $expected = hash_hmac($algo, $request->getContent(), $this->container->getParameter('github.webhook_secret'));
                if (hash_equals($expected, $sig)) {
                    $packages = $this->findPackagesByRepository('https://github.com/'.$match['path']);
                    $autoUpdated = Package::AUTO_GITHUB_HOOK;
                }
            }
        }

        if (!$packages) {
            if (!$user) {
                return new Response(json_encode(array('status' => 'error', 'message' => 'Invalid credentials')), 403);
            }

            // try to find the user package
            $packages = $this->findPackagesByUrl($user, $url, $urlRegex);
        }

        if (!$packages) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)')), 404);
        }

        // put both updating the database and scanning the repository in a transaction
        $em = $this->get('doctrine.orm.entity_manager');
        $jobs = [];

        /** @var Package $package */
        foreach ($packages as $package) {
            $package->setAutoUpdated($autoUpdated);
            $em->flush($package);

            $job = $this->get('scheduler')->scheduleUpdate($package);
            $jobs[] = $job->getId();
        }

        return new JsonResponse(['status' => 'success', 'jobs' => $jobs], 202);
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

        $user = $this->get('packagist.user_repository')
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
    protected function findPackagesByUrl(User $user, $url, $urlRegex)
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
            }
        }

        return $packages;
    }

    /**
     * @param string $url
     * @return array the packages found
     */
    protected function findPackagesByRepository(string $url): array
    {
        return $this->getDoctrine()->getRepository('PackagistWebBundle:Package')->findBy(['repository' => $url]);
    }
}
