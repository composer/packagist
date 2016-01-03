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
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

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

        if (isset($payload['repository']['url'])) { // github/gitlab/anything hook
            $urlRegex = '{^(?:ssh://git@|https?://|git://|git@)?(?P<host>[a-z0-9.-]+)(?::[0-9]+/|[:/])(?P<path>[\w.-]+/[\w.-]+?)(?:\.git|/)?$}i';
            $url = $payload['repository']['url'];
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
     * @Route("/downloads/{name}", name="track_download", requirements={"name"="[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+"}, defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function trackDownloadAction(Request $request, $name)
    {
        $result = $this->getPackageAndVersionId($name, $request->request->get('version_normalized'));

        if (!$result) {
            return new JsonResponse(array('status' => 'error', 'message' => 'Package not found'), 200);
        }

        $this->trackDownload($result['id'], $result['vid'], $request->getClientIp());

        return new JsonResponse(array('status' => 'success'), 201);
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
        foreach ($contents['downloads'] as $package) {
            $result = $this->getPackageAndVersionId($package['name'], $package['version']);

            if (!$result) {
                $failed[] = $package;
                continue;
            }

            $this->trackDownload($result['id'], $result['vid'], $request->getClientIp());
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

    protected function trackDownload($id, $vid, $ip)
    {
        $redis = $this->get('snc_redis.default');
        $manager = $this->get('packagist.download_manager');

        $throttleKey = 'dl:'.$id.':'.$ip.':'.date('Ymd');
        $requests = $redis->incr($throttleKey);
        if (1 === $requests) {
            $redis->expire($throttleKey, 86400);
        }
        if ($requests <= 10) {
            $manager->addDownload($id, $vid);
        }
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
        if (!preg_match($urlRegex, $url)) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Could not parse payload repository URL')), 406);
        }

        // find the user
        $user = $this->findUser($request);

        if (!$user) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Invalid credentials')), 403);
        }

        // try to find the user package
        $packages = $this->findPackagesByUrl($user, $url, $urlRegex);

        if (!$packages) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)')), 404);
        }

        // don't die if this takes a while
        set_time_limit(3600);

        // put both updating the database and scanning the repository in a transaction
        $em = $this->get('doctrine.orm.entity_manager');
        $updater = $this->get('packagist.package_updater');
        $config = Factory::createConfig();
        $io = new BufferIO('', OutputInterface::VERBOSITY_VERY_VERBOSE, new HtmlOutputFormatter(Factory::createAdditionalStyles()));
        $io->loadConfiguration($config);

        try {
            /** @var Package $package */
            foreach ($packages as $package) {
                $em->transactional(function($em) use ($package, $updater, $io, $config) {
                    // prepare dependencies
                    $loader = new ValidatingArrayLoader(new ArrayLoader());

                    // prepare repository
                    $repository = new VcsRepository(array('url' => $package->getRepository()), $io, $config);
                    $repository->setLoader($loader);

                    // perform the actual update (fetch and re-scan the repository's source)
                    $updater->update($io, $config, $package, $repository);

                    // update the package entity
                    $package->setAutoUpdated(true);
                    $em->flush();
                });
            }
        } catch (\Exception $e) {
            if ($e instanceof InvalidRepositoryException) {
                $this->get('packagist.package_manager')->notifyUpdateFailure($package, $e, $io->getOutput());
            }

            return new Response(json_encode(array(
                'status' => 'error',
                'message' => '['.get_class($e).'] '.$e->getMessage(),
                'details' => '<pre>'.$io->getOutput().'</pre>'
            )), 400);
        }

        return new JsonResponse(array('status' => 'success'), 202);
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

        $user = $this->get('packagist.user_repository')
            ->findOneBy(array('username' => $username, 'apiToken' => $apiToken));

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
            if (preg_match($urlRegex, $package->getRepository(), $candidate)
                && strtolower($candidate['host']) === strtolower($matched['host'])
                && strtolower($candidate['path']) === strtolower($matched['path'])
            ) {
                $packages[] = $package;
            }
        }

        return $packages;
    }
}
