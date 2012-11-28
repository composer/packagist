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

use Composer\IO\BufferIO;
use Composer\Factory;
use Composer\Repository\VcsRepository;
use Composer\Repository\InvalidRepositoryException;
use Composer\Package\Loader\ValidatingArrayLoader;
use Composer\Package\Loader\ArrayLoader;
use Packagist\WebBundle\Package\Updater;
use Packagist\WebBundle\Entity\Package;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Console\Output\OutputInterface;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Template;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Route;
use Sensio\Bundle\FrameworkExtraBundle\Configuration\Method;

/**
 * @author Jordi Boggiano <j.boggiano@seld.be>
 */
class ApiController extends Controller
{
    /**
     * @Template()
     * @Route("/packages.json", name="packages", defaults={"_format" = "json"})
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

        $em = $this->get('doctrine')->getEntityManager();

        gc_enable();

        $packages = $em->getRepository('Packagist\WebBundle\Entity\Package')
            ->getFullPackages();

        $notifyUrl = $this->generateUrl('track_download', array('name' => 'VND/PKG'));

        $data = array(
            'notify' => str_replace('VND/PKG', '%package%', $notifyUrl),
            'packages' => array(),
        );
        foreach ($packages as $package) {
            $versions = array();
            foreach ($package->getVersions() as $version) {
                $versions[$version->getVersion()] = $version->toArray();
                $em->detach($version);
            }
            $data['packages'][$package->getName()] = $versions;
            $em->detach($package);
        }
        unset($versions, $package, $packages);

        $response = new Response(json_encode($data), 200);
        $response->setSharedMaxAge(120);
        return $response;
    }

    /**
     * @Route("/api/github", name="github_postreceive", defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function githubPostReceive(Request $request)
    {
        return $this->receivePost($request, '{^(?:https?://|git://|git@)?(?P<domain>github\.com)[:/](?P<repo>[\w.-]+/[\w.-]+?)(?:\.git)?$}');
    }

    /**
     * @Route("/api/bitbucket", name="bitbucket_postreceive", defaults={"_format" = "json"})
     * @Method({"POST"})
     */
    public function bitbucketPostReceive(Request $request)
    {
        return $this->receivePost($request, '{^(?:https?://)?(?P<domain>bitbucket\.org)/(?P<repo>[\w.-]+/[\w.-]+?)/?$}');
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

        $throttleKey = 'dl:'.$id.':'.$ip.':'.date('Ymd');
        $requests = $redis->incr($throttleKey);
        if (1 === $requests) {
            $redis->expire($throttleKey, 86400);
        }
        if ($requests <= 10) {
            $redis->incr('downloads');

            $redis->incr('dl:'.$id);
            $redis->incr('dl:'.$id.':'.date('Ym'));
            $redis->incr('dl:'.$id.':'.date('Ymd'));

            $redis->incr('dl:'.$id.'-'.$vid);
            $redis->incr('dl:'.$id.'-'.$vid.':'.date('Ym'));
            $redis->incr('dl:'.$id.'-'.$vid.':'.date('Ymd'));
        }
    }

    protected function receivePost(Request $request, $urlRegex)
    {
        $payload = json_decode($request->request->get('payload'), true);
        if (!$payload || !isset($payload['repository']['url'])) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Missing or invalid payload',)), 406);
        }

        // try to parse the URL first to avoid the DB lookup on malformed requests
        if (!preg_match($urlRegex, $payload['repository']['url'], $requestedRepo)) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Could not parse payload repository URL',)), 406);
        }

        $username = $request->request->has('username') ?
            $request->request->get('username') :
            $request->query->get('username');

        $apiToken = $request->request->has('apiToken') ?
            $request->request->get('apiToken') :
            $request->query->get('apiToken');

        $user = $this->get('packagist.user_repository')
            ->findOneBy(array('username' => $username, 'apiToken' => $apiToken));

        if (!$user) {
            return new Response(json_encode(array('status' => 'error', 'message' => 'Invalid credentials',)), 403);
        }

        $updated = false;
        $config = Factory::createConfig();
        $loader = new ValidatingArrayLoader(new ArrayLoader());
        $updater = $this->get('packagist.package_updater');
        $em = $this->get('doctrine.orm.entity_manager');

        foreach ($user->getPackages() as $package) {
            if (preg_match($urlRegex, $package->getRepository(), $candidate)
                && $candidate['domain'] === $requestedRepo['domain']
                && $candidate['repo'] === $requestedRepo['repo']
            ) {
                set_time_limit(3600);
                $updated = true;

                $io = new BufferIO('', OutputInterface::VERBOSITY_VERBOSE);
                $repository = new VcsRepository(array('url' => $package->getRepository()), $io, $config);
                $repository->setLoader($loader);
                $package->setAutoUpdated(true);
                $em->flush();
                try {
                    $updater->update($package, $repository);
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
            }
        }

        if ($updated) {
            return new Response('{"status": "success"}', 202);
        }

        return new Response(json_encode(array('status' => 'error', 'message' => 'Could not find a package that matches this request (does user maintain the package?)',)), 404);
    }
}
